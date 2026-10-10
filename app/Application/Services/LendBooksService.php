<?php
declare(strict_types=1);
namespace App\Application\Services;
use App\Application\Ports\BookRepository;
use App\Application\Ports\LendingRepository;
use App\Domain\Entities\Book;
use App\Domain\Entities\Loan;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
final readonly class LendBooksService {
    public function __construct(private BookRepository $books, private LendingRepository $lending) {}
    /**
     * @param list<string> $bookIds
     * @param list<string> $itemIds
     */
    public function execute(string $loanId, string $memberId, string $userId, array $bookIds, array $itemIds, DateTimeImmutable $now): Loan {
        if (count($bookIds) < 1 || count($bookIds) > 3 || count($bookIds) !== count($itemIds)) {
            throw new InvalidArgumentException('LOAN_REQUIRES_ONE_TO_THREE_ITEMS');
        }
        if (count(array_unique($bookIds)) !== count($bookIds) || count(array_unique($itemIds)) !== count($itemIds)) {
            throw new InvalidArgumentException('DUPLICATE_BOOK_OR_ITEM');
        }
        if (!$this->lending->memberExists($memberId)) {
            throw new DomainException('MEMBER_NOT_FOUND');
        }
        if ($this->lending->memberHasOverdueItems($memberId, $now)) {
            throw new DomainException('MEMBER_HAS_OVERDUE_ITEMS');
        }
        $outstanding = $this->lending->outstandingItemsCount($memberId);
        if ($outstanding + count($bookIds) > 3) {
            throw new DomainException('MEMBER_LOAN_LIMIT:'.$outstanding);
        }
        $loan = new Loan($loanId, $memberId, $userId, $now);
        $selected = [];
        foreach ($bookIds as $index => $bookId) {
            $book = $this->books->findById($bookId);
            if ($book === null || !$book->isActive()) {
                throw new DomainException('BOOK_NOT_FOUND');
            }
            if ($book->availableCopies < 1) {
                throw new DomainException('BOOK_OUT_OF_STOCK');
            }
            $categoryName = $this->lending->categoryName($book->categoryId);
            if ($categoryName === null) {
                throw new DomainException('CATEGORY_NOT_FOUND');
            }
            $loan->addItem($itemIds[$index], $book->id, $book->title, $book->isbn->value, $categoryName);
            $selected[] = $book;
        }
        $loan->assertReadyToRegister();
        // No stock is modified until every validation succeeds.
        // The persistence adapter must atomically lock/check/update copies and save the loan.
        $this->lending->commitLoan($loan, $selected);
        return $loan;
    }
}