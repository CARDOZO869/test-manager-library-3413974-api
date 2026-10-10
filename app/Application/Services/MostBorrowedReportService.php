<?php
declare(strict_types=1);
namespace App\Application\Services;
use App\Application\Ports\MostBorrowedReportReader;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
final readonly class MostBorrowedReportService {
    public function __construct(private MostBorrowedReportReader $reader) {}
    /**
     * @return array{from:string,to:string,rows:list<array{book_id:string,book_title:string,category_name:string,times_borrowed:int}>}
     */
    public function execute(string $actorRole, string $from, string $to): array {
        if ($actorRole !== 'admin') {
            throw new DomainException('FORBIDDEN');
        }
        $start = $this->parseDate($from);
        $end = $this->parseDate($to);
        if ($end < $start) {
            throw new InvalidArgumentException('INVALID_DATE_RANGE');
        }
        $untilExclusive = $end->modify('+1 day');
        $groups = [];
        foreach ($this->reader->borrowedSnapshots($start, $untilExclusive) as $snapshot) {
            $key = json_encode([$snapshot['book_id'], $snapshot['book_title'], $snapshot['category_name']], JSON_THROW_ON_ERROR);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'book_id' => $snapshot['book_id'],
                    'book_title' => $snapshot['book_title'],
                    'category_name' => $snapshot['category_name'],
                    'times_borrowed' => 0,
                ];
            }
            $groups[$key]['times_borrowed']++;
        }
        $rows = array_values($groups);
        usort($rows, static fn (array $a, array $b): int =>
            ($b['times_borrowed'] <=> $a['times_borrowed'])
            ?: strcmp($a['book_title'], $b['book_title'])
            ?: strcmp($a['category_name'], $b['category_name'])
            ?: strcmp($a['book_id'], $b['book_id'])
        );
        return ['from' => $from, 'to' => $to, 'rows' => $rows];
    }
    private function parseDate(string $value): DateTimeImmutable {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('INVALID_DATE');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('INVALID_DATE');
        }
        return $date;
    }
}