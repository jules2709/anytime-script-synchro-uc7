<?php
declare(strict_types=1);

namespace HistorySync\Application\CommandHandler;

use HistorySync\Application\Command\SyncHistoryToZendeskCommand;
use HistorySync\Domain\Repository\MessageRepositoryInterface;
use HistorySync\Domain\Repository\SuncoRepositoryInterface;
use HistorySync\Domain\Repository\ZendeskRepositoryInterface;

class SyncHistoryToZendeskHandler
{
    /** @var array<string, true> */
    private array $processedIds = [];
    private ?string $progressFilePath = null;

    public function __construct(
        private readonly MessageRepositoryInterface $messageRepository,
        private readonly SuncoRepositoryInterface $suncoRepository,
        private readonly ZendeskRepositoryInterface $zendeskRepository
    ) {
    }

    public function __invoke(SyncHistoryToZendeskCommand $command): void
    {
        $this->progressFilePath = $command->progressFile;

        if ($command->externalId !== null) {
            // Mode utilisateur unique : pas de fichier de progression
            $success = $this->processUser(
                $command->externalId,
                $command->cutoffDate,
                $command->conversationTitle,
                $command->ticketTitle,
                $command->ticketTags
            );
            if (!$success) {
                throw new \RuntimeException("Échec de la synchronisation pour l'utilisateur {$command->externalId}");
            }
            return;
        }

        // Mode tous les utilisateurs : charger la progression existante
        $this->loadProgress();

        $userIds = $this->messageRepository->getAllUserIds($command->cutoffDate);
        if (empty($userIds)) {
            echo "❌ Aucun utilisateur trouvé dans la base.\n";
            return;
        }

        $total = count($userIds);
        $skippedCount = 0;
        $successCount = 0;
        $failCount = 0;
        $failedIds = [];

        if (!empty($this->processedIds)) {
            echo "Fichier de progression chargé : " . count($this->processedIds) . " utilisateur(s) déjà traité(s).\n";
        }
        echo "\nLancement de la synchronisation pour {$total} utilisateur(s)...\n\n";

        foreach ($userIds as $index => $uid) {
            $num = $index + 1;

            // Skip si déjà traité
            if (isset($this->processedIds[$uid])) {
                $skippedCount++;
                echo "[{$num}/{$total}] Utilisateur {$uid} déjà traité, ignoré.\n";
                continue;
            }

            echo "\n[{$num}/{$total}]";

            if ($this->processUser($uid, $command->cutoffDate, $command->conversationTitle, $command->ticketTitle, $command->ticketTags)) {
                $successCount++;
                $this->appendProcessedId($uid);
            } else {
                $failCount++;
                $failedIds[] = $uid;
            }
        }

        // Résumé final
        echo "\n\n" . str_repeat("=", 60) . "\n";
        echo "Résumé de la synchronisation globale\n";
        echo str_repeat("=", 60) . "\n";
        echo "Réussis  : {$successCount}/{$total}\n";
        echo "Ignorés  : {$skippedCount}/{$total} (déjà traités)\n";
        echo "Échoués  : {$failCount}/{$total}\n";
        if (!empty($failedIds)) {
            echo "   IDs en échec : " . implode(', ', $failedIds) . "\n";
        }
        echo str_repeat("=", 60) . "\n";
    }

    /**
     * Charge les IDs déjà traités depuis le fichier de progression.
     */
    private function loadProgress(): void
    {
        if ($this->progressFilePath === null || !file_exists($this->progressFilePath)) {
            return;
        }

        $content = file_get_contents($this->progressFilePath);
        if ($content === false) {
            return;
        }

        $lines = array_filter(array_map('trim', explode("\n", $content)));
        foreach ($lines as $line) {
            $this->processedIds[$line] = true;
        }
    }

    /**
     * Ajoute un ID traité au fichier de progression (flush immédiat).
     */
    private function appendProcessedId(string $externalId): void
    {
        if ($this->progressFilePath === null) {
            return;
        }

        $handle = fopen($this->progressFilePath, 'a');
        if ($handle !== false) {
            fwrite($handle, $externalId . "\n");
            fflush($handle);
            fclose($handle);
        }
    }

    /**
     * @param string[] $ticketTags
     */
    private function processUser(
        string $externalId,
        string $cutoffDate,
        string $conversationTitle,
        string $ticketTitle,
        array $ticketTags
    ): bool {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "Traitement de l'utilisateur : {$externalId}\n";
        echo str_repeat("=", 60) . "\n\n";

        // Charger les messages depuis MariaDB
        $messages = $this->messageRepository->loadMessages($externalId, $cutoffDate);
        if (empty($messages)) {
            echo "Aucun message pour l'utilisateur {$externalId}, ignoré.\n";
            return true;
        }

        // Déterminer le statut du ticket depuis le dernier message client
        $lastClientMessages = array_filter($messages, static fn($m) => $m->authorType === 'user');
        if (!empty($lastClientMessages)) {
            $lastClient = end($lastClientMessages);
            $ticketStatus = $lastClient->done === 1 ? 'solved' : 'open';
        } else {
            $ticketStatus = 'solved';
        }

        echo "Statut du ticket déterminé depuis m_done : {$ticketStatus}\n";

        // Enregistrer le timestamp de démarrage (pour filtrer le ticket créé par SunCo)
        $startTime = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        echo "Démarrage de la synchronisation pour l'utilisateur {$externalId}...\n";
        echo "   Timestamp : " . $startTime->format('c') . "\n\n";

        // 1. Supprimer l'utilisateur SunCo pour repartir de zéro
        if (!$this->suncoRepository->deleteUser($externalId)) {
            echo "❌ Impossible de supprimer l'utilisateur SunCo.\n";
            return false;
        }

        // 2. Créer/vérifier l'utilisateur SunCo
        if (!$this->suncoRepository->ensureUser($externalId, "User {$externalId}")) {
            echo "❌ Impossible de créer/vérifier l'utilisateur SunCo.\n";
            return false;
        }

        // 3. Créer la conversation SunCo avec les messages (déclenche la création auto du ticket Zendesk)
        $conversationId = $this->suncoRepository->createConversation($externalId, $messages, $conversationTitle);
        if ($conversationId === null) {
            echo "❌ Impossible de créer la conversation SunCo.\n";
            return false;
        }

        // 4. Trouver le ticket Zendesk créé par SunCo (créé APRÈS startTime)
        $ticketId = $this->zendeskRepository->findLatestTicketForUser($externalId, $startTime);
        if ($ticketId === null) {
            echo "❌ Impossible de trouver le ticket Zendesk créé par SunCo.\n";
            return false;
        }

        // 5. Mettre à jour le ticket avec titre, statut et tags
        if (!$this->zendeskRepository->updateTicket($ticketId, $ticketTitle, $ticketStatus, $ticketTags)) {
            echo "❌ Impossible de mettre à jour le ticket Zendesk.\n";
            return false;
        }

        echo str_repeat("=", 60) . "\n";
        echo "Synchronisation terminée avec succès !\n\n";
        echo "Conversation SunCo : {$conversationId}\n";
        echo "Ticket Zendesk : #{$ticketId}\n";
        echo "   Messages ajoutés et ticket mis à jour\n\n";
        echo str_repeat("=", 60) . "\n";

        return true;
    }
}
