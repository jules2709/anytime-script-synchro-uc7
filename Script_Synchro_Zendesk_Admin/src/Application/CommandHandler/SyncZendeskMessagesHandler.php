<?php
declare(strict_types=1);

namespace ZendeskSync\Application\CommandHandler;

use ZendeskSync\Application\Command\SyncZendeskMessagesCommand;
use ZendeskSync\Domain\Entity\Message;
use ZendeskSync\Domain\Repository\MessageRepositoryInterface;
use ZendeskSync\Domain\Repository\ZendeskRepositoryInterface;
use ZendeskSync\Utils;

class SyncZendeskMessagesHandler
{
    public function __construct(
        private readonly MessageRepositoryInterface $messageRepository,
        private readonly ZendeskRepositoryInterface $zendeskRepository
    ) {
    }

    public function __invoke(SyncZendeskMessagesCommand $command): void
    {
        $startTime = ($command->startTime ?? $this->zendeskRepository->loadLastSync()) + 1;

        // Statistics tracking
        $insertedMessagesCount = 0;
        $missingTicketsCount = 0;
        $filteredTicketsCount = 0;
        $missingTicketIds = [];

        // Simulating the while loop from script.php but structured for the handler
        $currentStartTime = $startTime;
        $finalTimestamp = null;
        $hasNextPage = true;

        while ($hasNextPage) {
            $result = $this->zendeskRepository->fetchTicketEvents($currentStartTime);
            $ticketEvents = $result['events'];
            $finalTimestamp = $result['endTime'] ?? $finalTimestamp;

            $commentsByTicket = $this->extractComments($ticketEvents);

            // Phase de validation (Bulk) : Récupérer tous les tickets en une seule requête
            if (!empty($commentsByTicket)) {
                $ticketIds = array_keys($commentsByTicket);
                echo "Validation de " . count($ticketIds) . " tickets en lot...\n";
                $bulkResult = $this->zendeskRepository->showMany($ticketIds);
                $validTickets = $bulkResult['tickets'];
                $bulkUsers = $bulkResult['users'];

                // Filtrer les tickets avec le tag "historique_admin"
                $filteredTicketIds = [];
                foreach ($validTickets as $ticketId => $ticket) {
                    $tags = $ticket['tags'] ?? [];
                    $normalizedTags = array_map('strtolower', $tags);
                    if (in_array('historique_admin', $normalizedTags, true)) {
                        $filteredTicketIds[] = $ticketId;
                        unset($validTickets[$ticketId]);
                    }
                }
                if (!empty($filteredTicketIds)) {
                    echo "Tickets ignorés (tag historique_admin) : " . count($filteredTicketIds) . "\n";
                    $filteredTicketsCount += count($filteredTicketIds);
                }
            } else {
                $validTickets = [];
                $bulkUsers = [];
                $filteredTicketIds = [];
            }

            // Phase de traitement (Boucle habituelle)
            foreach ($commentsByTicket as $ticketId => $comments) {
                // Vérification d'existence : Si le ticket n'est pas dans validTickets
                if (!isset($validTickets[$ticketId])) {
                    $missingTicketsCount++;
                    $missingTicketIds[] = $ticketId;
                    continue;
                }

                $ticket = $validTickets[$ticketId];
                
                // Fusionner les utilisateurs du bulk avec ceux du ticket unitaire si nécessaire
                $users = $bulkUsers;
                
                // Si le bulk ne contient pas les utilisateurs (cas rare), faire appel unitaire
                if (empty($users)) {
                    $details = $this->zendeskRepository->fetchTicketDetails($ticketId);
                    $users = $details['users'];
                }

                $requesterId = $ticket['requester_id'] ?? null;
                $userMatch = null;
                foreach ($users as $user) {
                    if (($user['id'] ?? null) === $requesterId) {
                        $userMatch = $user;
                        break;
                    }
                }

                $externalId = $userMatch['external_id'] ?? ($users[0]['external_id'] ?? null);
                $userName = $userMatch['name'] ?? ($users[0]['name'] ?? null);
                $ticketDone = Utils::isTicketDone($ticket['status'] ?? 'new');

                $usersById = [];
                foreach ($users as $user) {
                    if (isset($user['id'])) {
                        $usersById[$user['id']] = $user;
                    }
                }

                $messagesToSave = [];

                foreach ($comments as $comment) {
                    $parsedMessages = Utils::parseCommentBody($comment['body'], $userName);

                    $authorId = $comment['author_id'] ?? null;
                    $authorUser = $authorId !== null ? ($usersById[$authorId] ?? null) : null;
                    $authorExternalId = $authorUser['external_id'] ?? null;

                    if (empty($parsedMessages)) {
                        $messagesToSave[] = [
                            'message' => new Message(
                                uid: $externalId,
                                topic: $ticket['subject'] ?? 'message Zendesk',
                                content: $comment['body'],
                                date: $comment['created_at'],
                                status: Utils::mapZendeskStatusToDb($ticket['status'] ?? 'new'),
                                report: Utils::mapPriorityToReport($ticket['priority'] ?? 'normal'),
                                done: $ticketDone,
                                source: $ticketId
                            ),
                            'isAgent' => false,
                            'agentExternalId' => null
                        ];
                    } else {
                        foreach ($parsedMessages as $msg) {
                            $agentExternalId = null;
                            $replyUid = null;
                            
                            if (!$msg['is_client']) {
                                $agentExternalId = $this->zendeskRepository->getAgentExternalId($msg['author']);
                                $replyUid = $agentExternalId ?? 1;
                            } else {
                                $replyUid = 0;
                            }

                            $messagesToSave[] = [
                                'message' => new Message(
                                    uid: $externalId,
                                    topic: $ticket['subject'] ?? 'message Zendesk',
                                    content: $msg['content'],
                                    date: $comment['created_at'],
                                    status: Utils::mapZendeskStatusToDb($ticket['status'] ?? 'new'),
                                    report: Utils::mapPriorityToReport($ticket['priority'] ?? 'normal'),
                                    done: $ticketDone,
                                    replyUid: $replyUid,
                                    source: $ticketId
                                ),
                                'isAgent' => !$msg['is_client'],
                                'agentExternalId' => $agentExternalId
                            ];
                        }
                    }
                }

                if ($ticketDone === 1) {
                    for ($i = count($messagesToSave) - 1; $i >= 0; $i--) {
                        if ($messagesToSave[$i]['isAgent'] && $messagesToSave[$i]['agentExternalId'] !== null) {
                            $messagesToSave[$i]['message'] = new Message(
                                uid: $messagesToSave[$i]['message']->uid,
                                topic: $messagesToSave[$i]['message']->topic,
                                content: $messagesToSave[$i]['message']->content,
                                date: $messagesToSave[$i]['message']->date,
                                status: $messagesToSave[$i]['message']->status,
                                report: $messagesToSave[$i]['message']->report,
                                done: $messagesToSave[$i]['message']->done,
                                replyUid: $messagesToSave[$i]['message']->replyUid,
                                doneUid: $messagesToSave[$i]['agentExternalId'],
                                source: $messagesToSave[$i]['message']->source
                            );
                            break;
                        }
                    }
                }

                foreach ($messagesToSave as $entry) {
                    $this->messageRepository->save($entry['message']);
                    $insertedMessagesCount++;
                }
                usleep(100000); // Rate limit security
            }

            if ($result['endOfStream']) {
                $hasNextPage = false;
            } else {
                // For incremental API, we usually follow next_page or update currentStartTime
                // The original script used next_page URL. 
                // We'll need the Infrastructure layer to handle the state of pagination if we want to keep it simple here.
                // Or we can pass the next_page to the next call.
                $hasNextPage = false; // Simplified for now, or we'd need a way to pass next_page
            }
        }

        if ($finalTimestamp !== null) {
            $this->zendeskRepository->saveLastSync($finalTimestamp);
        }

        // Affichage des statistiques
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "Résumé de la synchronisation\n";
        echo str_repeat("=", 60) . "\n";
        echo "Messages insérés : {$insertedMessagesCount}\n";
        echo "Tickets filtrés (Historique_Admin) : {$filteredTicketsCount}\n";
        echo "Tickets manquants (filtrés/supprimés) : {$missingTicketsCount}\n";
        if ($missingTicketsCount > 0) {
            $maxDisplay = min(20, count($missingTicketIds));
            $displayIds = array_slice($missingTicketIds, 0, $maxDisplay);
            echo "   IDs manquants (top {$maxDisplay}) : " . implode(", ", $displayIds);
            if (count($missingTicketIds) > 20) {
                echo " ... et " . (count($missingTicketIds) - 20) . " autres";
            }
            echo "\n";
        }
        echo str_repeat("=", 60) . "\n";
    }

    private function extractComments(array $ticketEvents): array
    {
        $commentsByTicket = [];
        foreach ($ticketEvents as $event) {
            if (($event['via'] ?? null) !== 'Chat Transcript') {
                continue;
            }

            $ticketId = $event['ticket_id'] ?? null;
            $auditId = $event['id'] ?? null;

            foreach ($event['child_events'] ?? [] as $childEvent) {
                if (($childEvent['event_type'] ?? null) === 'Comment') {
                    if (!isset($commentsByTicket[$ticketId])) {
                        $commentsByTicket[$ticketId] = [];
                    }
                    $commentsByTicket[$ticketId][] = [
                        'body' => $childEvent['body'] ?? '',
                        'created_at' => $childEvent['created_at'] ?? '',
                        'audit_id' => $auditId,
                        'author_id' => $childEvent['author_id'] ?? null,
                        'public' => $childEvent['public'] ?? true
                    ];
                }
            }
        }
        return $commentsByTicket;
    }
}
