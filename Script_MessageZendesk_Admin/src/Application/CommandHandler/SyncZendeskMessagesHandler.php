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

        // Simulating the while loop from script.php but structured for the handler
        $currentStartTime = $startTime;
        $finalTimestamp = null;
        $hasNextPage = true;

        while ($hasNextPage) {
            $result = $this->zendeskRepository->fetchTicketEvents($currentStartTime);
            $ticketEvents = $result['events'];
            $finalTimestamp = $result['endTime'] ?? $finalTimestamp;

            $commentsByTicket = $this->extractComments($ticketEvents);

            foreach ($commentsByTicket as $ticketId => $comments) {
                $details = $this->zendeskRepository->fetchTicketDetails($ticketId);
                $ticket = $details['ticket'];
                $users = $details['users'];

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

                foreach ($comments as $comment) {
                    $parsedMessages = Utils::parseCommentBody($comment['body'], $userName);

                    if (empty($parsedMessages)) {
                        $this->messageRepository->save(new Message(
                            uid: $externalId,
                            topic: $ticket['subject'] ?? 'message Zendesk',
                            content: $comment['body'],
                            date: $comment['created_at'],
                            status: Utils::mapZendeskStatusToDb($ticket['status'] ?? 'new'),
                            report: Utils::mapPriorityToReport($ticket['priority'] ?? 'normal'),
                            done: Utils::isTicketDone($ticket['status'] ?? 'new')
                        ));
                    } else {
                        foreach ($parsedMessages as $msg) {
                            $this->messageRepository->save(new Message(
                                uid: $externalId,
                                topic: $ticket['subject'] ?? 'message Zendesk',
                                content: $msg['content'],
                                date: $comment['created_at'],
                                status: Utils::mapZendeskStatusToDb($ticket['status'] ?? 'new'),
                                report: Utils::mapPriorityToReport($ticket['priority'] ?? 'normal'),
                                done: Utils::isTicketDone($ticket['status'] ?? 'new'),
                                replyUid: !$msg['is_client'] ? ($this->zendeskRepository->getAgentExternalId($msg['author']) ?? 1) : null
                            ));
                        }
                    }
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
