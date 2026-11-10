--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Mechanic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Regression for #2249: the stubs declared `$column` narrower than Laravel, which documents
 * `Illuminate\Contracts\Database\Query\Expression` as a member at every supported version.
 * `DB::raw()` is the canonical way to pass an Expression column, so each call below is
 * valid Laravel and must not report InvalidArgument.
 *
 * Taint coverage of the same `$column` sinks stays in TaintedSqlWhereColumnSink.phpt (Query
 * `where()`) and TaintedSqlEloquentWhereColumnSink.phpt (Eloquent `where()`).
 */
function query_builder_where_accepts_expression_column(QueryBuilder $query): void
{
    $query->where(DB::raw('lower(email)'), 'a@b.c');
    $query->where(DB::raw('lower(email)'), '=', 'a@b.c');
}

function eloquent_builder_accepts_expression_column(): void
{
    Customer::query()->orWhere(DB::raw('lower(email)'), 'a@b.c');
    Customer::query()->where('id', 1)->orWhere(DB::raw('lower(email)'), '=', 'a@b.c');
    Customer::query()->whereDate(DB::raw('date(created_at)'), '2024-01-01');
    Customer::query()->latest(DB::raw('lower(email)'));
    Customer::query()->oldest(DB::raw('lower(email)'));
    Customer::query()->value(DB::raw('lower(email)'));
    Customer::query()->pluck(DB::raw('lower(email)'));
    Customer::query()->count(DB::raw('distinct email'));
}

function relation_accepts_expression_column(Customer $customer): void
{
    $customer->vehicles()->latest(DB::raw('lower(plate)'));
    $customer->vehicles()->oldest(DB::raw('lower(plate)'));
}

/** @param Builder<Customer> $builder */
function eloquent_builder_receiver_accepts_expression_column(Builder $builder): void
{
    $builder->orWhere(DB::raw('lower(email)'), 'a@b.c');
}

function query_builder_column_lists_accept_expression(QueryBuilder $query): void
{
    $query->find(1, DB::raw('lower(email)'));
    $query->find(1, [DB::raw('lower(email)')]);
    $query->get(DB::raw('lower(email)'));
    $query->get([DB::raw('lower(email)')]);
}

/** @param Builder<Customer> $builder */
function eloquent_builder_column_lists_accept_expression(Builder $builder): void
{
    $builder->find(1, [DB::raw('lower(email)')]);
    $builder->findMany([1, 2], [DB::raw('lower(email)')]);
    $builder->findOrFail(1, [DB::raw('lower(email)')]);
    $builder->first([DB::raw('lower(email)')]);
    $builder->firstOrFail([DB::raw('lower(email)')]);
    $builder->sole([DB::raw('lower(email)')]);
    $builder->getModels([DB::raw('lower(email)')]);
    $builder->paginate(15, [DB::raw('lower(email)')]);
    $builder->simplePaginate(15, [DB::raw('lower(email)')]);
}

function relation_column_lists_accept_expression(Customer $customer, Mechanic $mechanic): void
{
    $customer->vehicles()->get([DB::raw('lower(plate)')]);
    $customer->vehicles()->first([DB::raw('lower(plate)')]);
    $customer->vehicles()->firstOrFail([DB::raw('lower(plate)')]);
    $customer->vehicles()->sole([DB::raw('lower(plate)')]);
    $customer->vehicles()->paginate(15, [DB::raw('lower(plate)')]);
    $customer->workOrders()->first([DB::raw('lower(id)')]);
    $customer->workOrders()->find(1, [DB::raw('lower(id)')]);
    $mechanic->specializations()->find(1, [DB::raw('lower(name)')]);
    $mechanic->specializations()->findMany([1], [DB::raw('lower(name)')]);
    $mechanic->specializations()->findOrFail(1, [DB::raw('lower(name)')]);
    $mechanic->specializations()->first([DB::raw('lower(name)')]);
    $mechanic->specializations()->firstOrFail([DB::raw('lower(name)')]);
    $mechanic->specializations()->get([DB::raw('lower(name)')]);
}
?>
--EXPECTF--
