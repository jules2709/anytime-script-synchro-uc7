<?php
declare(strict_types=1);

namespace ZendeskSync;

use PDO;
use PDOException;
use Exception;

class Utils
{
    /**
     * Parse le body d'un commentaire pour séparer les messages client/agent.
     * 
     * Format attendu: "(HH:MM:SS) Nom: message"
     * 
     * @param string $body Contenu du commentaire
     * @param string|null $userName Nom du client pour déterminer qui est l'auteur
     * @return array Liste de dictionnaires avec 'timestamp', 'author', 'content', 'is_client'
     */
    public static function parseCommentBody(string $body, ?string $userName): array
    {
        $messages = [];
        // Regex pour capturer: (timestamp) Nom: message
        $pattern = '/\((\d{2}:\d{2}:\d{2})\)\s*([^:]+):\s*(.+?)(?=\(\d{2}:\d{2}:\d{2}\)|$)/s';
        
        preg_match_all($pattern, $body, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $timestamp = $match[1];
            $author = trim($match[2]);
            $content = trim($match[3]);
            
            // Vérifier si c'est le client ou l'agent
            $isClient = ($author === $userName);
            
            $messages[] = [
                'timestamp' => $timestamp,
                'author' => $author,
                'content' => $content,
                'is_client' => $isClient
            ];
        }
        
        return $messages;
    }

    /**
     * Établit la connexion à MariaDB.
     * 
     * @param array $dbConfig Configuration de la base de données
     * @return PDO Instance PDO connectée
     * @throws PDOException Si la connexion échoue
     */
    public static function getDbConnection(array $dbConfig): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $dbConfig['host'],
            $dbConfig['port'],
            $dbConfig['database']
        );
        
        $pdo = new PDO(
            $dsn,
            $dbConfig['user'],
            $dbConfig['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        
        return $pdo;
    }

    /**
     * Charge le dernier timestamp de synchronisation depuis last_sync.txt.
     * 
     * @return int Timestamp de la dernière synchronisation
     */
    public static function loadLastSync(): int
    {
        try {
            if (file_exists('last_sync.txt')) {
                $content = file_get_contents('last_sync.txt');
                if ($content !== false) {
                    $timestamp = (int)trim($content);
                    echo "Dernière synchronisation : timestamp {$timestamp}\n";
                    return $timestamp;
                }
            }
            
            // Par défaut, récupérer les modifs des dernières 24h
            $defaultTimestamp = time() - 86400;
            echo "Fichier last_sync.txt non trouvé, utilisation du timestamp par défaut : {$defaultTimestamp}\n";
            return $defaultTimestamp;
            
        } catch (Exception $e) {
            $defaultTimestamp = time() - 86400;
            echo "Erreur lecture last_sync.txt, utilisation du timestamp par défaut : {$defaultTimestamp}\n";
            return $defaultTimestamp;
        }
    }

    /**
     * Sauvegarde le nouveau timestamp de synchronisation dans last_sync.txt.
     * 
     * @param int $timestamp Timestamp à sauvegarder
     * @return void
     */
    public static function saveLastSync(int $timestamp): void
    {
        try {
            file_put_contents('last_sync.txt', (string)$timestamp);
            echo "Timestamp {$timestamp} sauvegardé dans last_sync.txt\n";
        } catch (Exception $e) {
            echo "Erreur lors de la sauvegarde du timestamp : {$e->getMessage()}\n";
        }
    }

    /**
     * Mappe le statut Zendesk vers le statut base de données.
     * Zendesk statuts: 'new', 'open', 'pending', 'solved', 'closed'
     * 
     * @param string $zendeskStatus Statut Zendesk
     * @return int Statut pour la base de données
     */
    public static function mapZendeskStatusToDb(string $zendeskStatus): int
    {
        $statusMap = [
            'new' => 1,
            'open' => 1,
            'pending' => 0,
            'solved' => 2,
            'closed' => 3
        ];
        
        return $statusMap[$zendeskStatus] ?? 1;
    }

    /**
     * Mappe la priorité Zendesk vers le champ m_report.
     * Priorités: 'low', 'normal', 'high', 'urgent'
     * 
     * @param string $priority Priorité Zendesk
     * @return int Valeur pour m_report
     */
    public static function mapPriorityToReport(string $priority): int
    {
        $priorityMap = [
            'low' => 0,
            'normal' => 1,
            'high' => 2,
            'urgent' => 3
        ];
        
        return $priorityMap[$priority] ?? 1;
    }

    /**
     * Détermine si le ticket est terminé (solved ou closed).
     * 
     * @param string $status Statut du ticket
     * @return int 1 si terminé, 0 sinon
     */
    public static function isTicketDone(string $status): int
    {
        return in_array($status, ['solved', 'closed']) ? 1 : 0;
    }

    /**
     * Insère les données mappées dans la table 'messages'.
     * 
     * Paramètres attendus:
     * - m_uid: ID utilisateur
     * - m_topic: Sujet/titre
     * - m_content: Contenu du message
     * - m_date: Date du message
     * - m_status: Statut du message (1=new/open, 0=pending, 2=solved, 3=closed)
     * - m_report: Niveau de priorité (0=low, 1=normal, 2=high, 3=urgent)
     * - m_done: Message terminé (0=non, 1=oui)
     * - m_reply_uid: (optionnel) ID de réponse pour les messages agents
     * 
     * @param PDO $pdo Instance PDO
     * @param array $messageData Données du message
     * @return void
     * @throws PDOException Si l'insertion échoue
     */
    public static function saveToMariadb(PDO $pdo, array $messageData): void
    {
        try {
            $fields = [
                'm_uid',
                'm_topic',
                'm_content',
                'm_date',
                'm_status',
                'm_report',
                'm_done',
                'm_source'
            ];

            $params = [
                ':m_uid' => $messageData['m_uid'],
                ':m_topic' => $messageData['m_topic'],
                ':m_content' => $messageData['m_content'],
                ':m_date' => $messageData['m_date'],
                ':m_status' => $messageData['m_status'],
                ':m_report' => $messageData['m_report'],
                ':m_done' => $messageData['m_done'],
                ':m_source' => $messageData['m_source']
            ];

            if (isset($messageData['m_reply_uid'])) {
                $fields[] = 'm_reply_uid';
                $params[':m_reply_uid'] = $messageData['m_reply_uid'];
            }

            if (isset($messageData['m_done_uid'])) {
                $fields[] = 'm_done_uid';
                $params[':m_done_uid'] = $messageData['m_done_uid'];
            }

            $placeholders = array_map(static fn(string $field): string => ':' . $field, $fields);
            $query = sprintf(
                "INSERT INTO messages (%s) VALUES (%s)",
                implode(', ', $fields),
                implode(', ', $placeholders)
            );

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
        } catch (PDOException $e) {
            echo "Erreur insertion : {$e->getMessage()}\n";
            throw $e;
        }
    }
}
