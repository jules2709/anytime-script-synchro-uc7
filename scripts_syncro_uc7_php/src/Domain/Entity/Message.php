<?php
declare(strict_types=1);

namespace ZendeskSync\Domain\Entity;

class Message
{
    public function __construct(
        public readonly ?string $uid,
        public readonly string $topic,
        public readonly string $content,
        public readonly string $date,
        public readonly int $status,
        public readonly int $report,
        public readonly int $done,
        public readonly string|int|null $replyUid = null,
        public readonly string|int|null $doneUid = null,
        public readonly string|int|null $source = null
    ) {
    }

    public function toArray(): array
    {
        $data = [
            'm_uid' => $this->uid,
            'm_topic' => $this->topic,
            'm_content' => $this->content,
            'm_date' => $this->date,
            'm_status' => $this->status,
            'm_report' => $this->report,
            'm_done' => $this->done,
        ];

        if ($this->replyUid !== null) {
            $data['m_reply_uid'] = $this->replyUid;
        }

        if ($this->doneUid !== null) {
            $data['m_done_uid'] = $this->doneUid;
        }

        if ($this->source !== null) {
            $data['m_source'] = $this->source;
        }

        return $data;
    }
}
