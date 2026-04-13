--FILE--
<?php declare(strict_types=1);

use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class CursorPaginatorSetCollectionTest
{
    /**
     * @param CursorPaginator<int, string> $paginator
     * @param Collection<int, int> $newCollection
     */
    public function setCollectionNarrowsType(CursorPaginator $paginator, Collection $newCollection): void
    {
        $paginator->setCollection($newCollection);

        $_collection = $paginator->getCollection();
        /** @psalm-check-type-exact $_collection = Collection<int, int> */
    }

    /**
     * @param LengthAwarePaginator<int, string> $paginator
     * @param Collection<int, int> $newCollection
     */
    public function setCollectionNarrowsLengthAwarePaginatorType(
        LengthAwarePaginator $paginator,
        Collection $newCollection,
    ): void {
        $paginator->setCollection($newCollection);

        $_collection = $paginator->getCollection();
        /** @psalm-check-type-exact $_collection = Collection<int, int> */
    }
}

?>
--EXPECT--
