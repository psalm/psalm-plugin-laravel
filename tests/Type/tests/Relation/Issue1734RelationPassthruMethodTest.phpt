--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\Shop;
use App\Models\WorkOrder;
use App\Models\WorkOrderNote;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar;

// #1734: Eloquent\Builder::$passthru methods reached through a relation. Relations see
// Eloquent\Builder via one @mixin hop only, so these must be declared on Eloquent\Builder.

function btm_exists(WorkOrder $w): bool { return $w->parts()->exists(); }
function btm_doesnt_exist(WorkOrder $w): bool { return $w->parts()->doesntExist(); }
function has_one_exists(WorkOrder $w): bool { return $w->invoice()->exists(); }
function has_many_exists(Mechanic $m): bool { return $m->workOrders()->exists(); }
function belongs_to_exists(WorkOrderNote $n): bool { return $n->parent()->exists(); }
function has_many_through_exists(Customer $c): bool { return $c->workOrders()->exists(); }
function morph_many_exists(Shop $s): bool { return $s->suppliers()->exists(); }
function morph_to_exists(Shop $s): bool { return $s->shopable()->exists(); }
function chained_where_exists(WorkOrder $w): bool { return $w->parts()->where('id', 1)->exists(); }
function chained_order_by_to_sql(Mechanic $m): string { return $m->workOrders()->orderBy('id')->toSql(); }
function to_raw_sql(WorkOrder $w): string { return $w->parts()->toRawSql(); }
function implode_column(Mechanic $m): string { return $m->workOrders()->implode('id', ','); }
/** @return list<mixed> */
function bindings(Mechanic $m): array { return $m->workOrders()->getBindings(); }
function insert_rows(Mechanic $m): bool { return $m->workOrders()->insert(['id' => 1]); }
function insert_get_id(Mechanic $m): int { return $m->workOrders()->insertGetId(['id' => 1]); }
/** @return int<0, max> */
function insert_or_ignore(Mechanic $m): int { return $m->workOrders()->insertOrIgnore(['id' => 1]); }
/** @return int<0, max> */
function count_for_pagination(Mechanic $m): int { return $m->workOrders()->getCountForPagination(); }
function raw_expr(Mechanic $m): Expression { return $m->workOrders()->raw('1'); }
function connection(Mechanic $m): ConnectionInterface { return $m->workOrders()->getConnection(); }
function grammar(Mechanic $m): Grammar { return $m->workOrders()->getGrammar(); }
function numeric_aggregate(Mechanic $m): float|int { return $m->workOrders()->numericAggregate('sum', ['id']); }

// Unchanged: count() was already declared; Eloquent builder receivers resolve as before.
/** @return int<0, max> */
function btm_count(WorkOrder $w): int { return $w->parts()->count(); }
function query_exists(): bool { return WorkOrder::query()->exists(); }
function static_exists(): bool { return WorkOrder::exists(); }
function query_to_sql(): string { return WorkOrder::query()->toSql(); }
?>
--EXPECTF--
