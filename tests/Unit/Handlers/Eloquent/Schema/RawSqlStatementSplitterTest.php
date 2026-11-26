<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\RawSqlStatementSplitter;

#[CoversClass(RawSqlStatementSplitter::class)]
final class RawSqlStatementSplitterTest extends TestCase
{
    #[Test]
    public function it_splits_on_semicolons_and_drops_empty_statements(): void
    {
        $this->assertSame(['A', 'B'], RawSqlStatementSplitter::split(" A ;; B ;\n"));
    }

    #[Test]
    public function it_keeps_a_semicolon_inside_a_string(): void
    {
        $this->assertSame(["INSERT INTO t VALUES ('a;b')", 'C'], RawSqlStatementSplitter::split("INSERT INTO t VALUES ('a;b'); C"));
    }

    #[Test]
    public function it_handles_a_doubled_quote_inside_a_string(): void
    {
        $this->assertSame(["X 'it''s;' Y", 'Z'], RawSqlStatementSplitter::split("X 'it''s;' Y; Z"));
    }

    #[Test]
    public function it_handles_a_backslash_escaped_quote_inside_a_string(): void
    {
        $this->assertSame(["X 'it\\'s; -- not a comment' Y", 'Z'], RawSqlStatementSplitter::split("X 'it\\'s; -- not a comment' Y; Z"));
    }

    #[Test]
    public function it_keeps_comment_markers_inside_backtick_and_double_quoted_identifiers(): void
    {
        $this->assertSame(['`a#b--c` "d#e--f" x'], RawSqlStatementSplitter::split('`a#b--c` "d#e--f" x -- tail'));
    }

    #[Test]
    public function it_strips_all_comment_styles(): void
    {
        $this->assertSame(['A   B', 'C'], RawSqlStatementSplitter::split("A /* ; */ B; -- x;\n# y;\nC"));
    }

    #[Test]
    public function it_keeps_semicolons_inside_dollar_quoted_bodies(): void
    {
        $sql = 'CREATE FUNCTION f() RETURNS void AS $$ BEGIN CREATE TABLE tmp (id int); END; $$ LANGUAGE plpgsql; CREATE TABLE real (id int)';

        $this->assertSame([
            'CREATE FUNCTION f() RETURNS void AS $$ BEGIN CREATE TABLE tmp (id int); END; $$ LANGUAGE plpgsql',
            'CREATE TABLE real (id int)',
        ], RawSqlStatementSplitter::split($sql));
    }

    #[Test]
    public function it_closes_a_tagged_dollar_quote_only_with_the_same_tag(): void
    {
        $sql = 'DO $body$ BEGIN PERFORM $$x;$$; END; $body$; B';

        $this->assertSame(['DO $body$ BEGIN PERFORM $$x;$$; END; $body$', 'B'], RawSqlStatementSplitter::split($sql));
    }

    #[Test]
    public function it_does_not_treat_positional_parameters_as_dollar_quotes(): void
    {
        $this->assertSame(['SELECT $1', 'SELECT $2'], RawSqlStatementSplitter::split('SELECT $1; SELECT $2'));
    }
}
