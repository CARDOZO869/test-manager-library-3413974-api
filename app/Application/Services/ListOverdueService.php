<?php
declare(strict_types=1);
namespace App\Application\Services;
use App\Application\Ports\ReturnsRepository;
use DateTimeImmutable;
final readonly class ListOverdueService {
    public function __construct(private ReturnsRepository $returns) {}
    /** @return list<array{loan_id:string,item_id:string,book_title:string,member_name:string,due_at:DateTimeImmutable,days_late:int}> */
    public function execute(DateTimeImmutable $now): array {
        $rows = [];
        foreach ($this->returns->overdueItems($now) as $item) {
            if ($item['due_at'] >= $now) continue;
            $seconds = $now->getTimestamp() - $item['due_at']->getTimestamp();
            $rows[] = [
                'loan_id' => $item['loan_id'],
                'item_id' => $item['item_id'],
                'book_title' => $item['book_title'],
                'member_name' => $item['member_name'],
                'due_at' => $item['due_at'],
                'days_late' => (int) ceil($seconds / 86400),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => ($b['days_late'] <=> $a['days_late']) ?: ($a['due_at'] <=> $b['due_at']));
        return $rows;
    }
}