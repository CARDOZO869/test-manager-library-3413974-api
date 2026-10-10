<?php
declare(strict_types=1);
namespace App\Application\Services;
use App\Application\Ports\ReturnsRepository;
use App\Domain\Entities\LoanItem;
use DateTimeImmutable;
use DomainException;
final readonly class ReturnBookService {
    public function __construct(private ReturnsRepository $returns) {}
    public function execute(string $loanId, string $itemId, DateTimeImmutable $when): LoanItem {
        $loan = $this->returns->findLoan($loanId);
        if ($loan === null) {
            throw new DomainException('LOAN_NOT_FOUND');
        }
        $selected = null;
        foreach ($loan->items() as $item) {
            if ($item->id === $itemId) {
                $selected = $item;
                break;
            }
        }
        if ($selected === null) {
            throw new DomainException('LOAN_ITEM_NOT_FOUND');
        }
        if ($selected->isReturned()) {
            throw new DomainException('ITEM_ALREADY_RETURNED');
        }
        $book = $this->returns->findBook($selected->bookId);
        if ($book === null) {
            throw new DomainException('BOOK_NOT_FOUND');
        }
        if ($book->availableCopies >= $book->totalCopies) {
            throw new DomainException('BOOK_STOCK_FULL');
        }
        // The adapter owns the transaction and concurrency checks.
        // Reload after committing so returned_at reflects persisted state.
        $this->returns->commitReturn($loanId, $itemId, $when);
        $updatedLoan = $this->returns->findLoan($loanId);
        if ($updatedLoan === null) {
            throw new DomainException('LOAN_NOT_FOUND');
        }
        foreach ($updatedLoan->items() as $item) {
            if ($item->id === $itemId) {
                return $item;
            }
        }
        throw new DomainException('LOAN_ITEM_NOT_FOUND');
    }
}