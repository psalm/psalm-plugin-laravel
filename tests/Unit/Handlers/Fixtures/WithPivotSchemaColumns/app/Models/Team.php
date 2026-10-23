<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

class Team extends Model
{
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role', 'note', 'rank', 'missing', 'created_at');
    }

    public function memberships(): BelongsToMany
    {
        $relation = $this->belongsToMany(User::class, 'memberships')->withPivot('admin');
        /** @psalm-trace $relation */

        return $relation;
    }

    public function baseMemberships(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships');
    }

    /** Delegation: the columns are typed from baseMemberships()'s table. */
    public function adminMemberships(): BelongsToMany
    {
        return $this->baseMemberships()->withPivot('admin');
    }

    public function stamped(): BelongsToMany
    {
        $relation = $this->belongsToMany(User::class, 'memberships_stamped')->withPivot('role')->withTimestamps();
        /** @psalm-trace $relation */

        return $relation;
    }

    /** Laravel reads the timestamp names off the factory's receiver (a Sheet), not off this model. */
    public function foreignStamps(): BelongsToMany
    {
        return (new Sheet())->belongsToMany(User::class, 'sheet_entries')->withTimestamps();
    }

    /** A `where()` between the factory and `withTimestamps()` may be anything, so no date is proven. */
    public function filteredStamps(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships_stamped')->where('role', 'admin')->withTimestamps();
    }

    /** The callback's relation joins another table: the columns must not read from this chain's `memberships_stamped`. */
    public function swappedTable(): BelongsToMany
    {
        $relation = $this->belongsToMany(User::class, 'memberships_stamped')
            ->when(true, fn() => $this->belongsToMany(User::class, 'memberships_other'))
            ->withPivot('role')
            ->withTimestamps('role_at', 'rank');
        /** @psalm-trace $relation */

        return $relation;
    }

    public function stampedBody(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships_stamped')->withPivot('role')->withTimestamps();
    }

    /** The outer `where()` is not known to keep the pivot class either: the delegated chain is not visible. */
    public function delegatedFilteredStamps(): BelongsToMany
    {
        return $this->stampedBody()->where('role', 'admin');
    }

    /** One branch is another model's factory, which takes that model's timestamp names: no shape on either. */
    public function branchedStamps(bool $foreign): BelongsToMany
    {
        return $this->branchedBase($foreign)->withTimestamps();
    }

    private function branchedBase(bool $foreign): BelongsToMany
    {
        if ($foreign) {
            return (new Sheet())->belongsToMany(User::class, 'sheet_entries');
        }

        return $this->belongsToMany(User::class, 'sheet_entries');
    }

    /** The pivot's own answer first (accessor, casts, its table's column), then the date gate and the relation table. */
    public function scored(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_scores')
            ->using(Score::class)
            ->withPivot('score', 'flags', 'rank', 'note', 'kind', 'created_at', 'updated_at');
    }

    /** The same chain at the call site inside a relation body. */
    public function scoredInBody(): BelongsToMany
    {
        $relation = $this->belongsToMany(User::class, 'team_scores')->withPivot('note')->using(Score::class)->withPivot('score');
        /** @psalm-trace $relation */

        return $relation;
    }

    /** `using()` last: the shape is rebuilt on the new class, whatever the call order. */
    public function scoredUsingLast(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_scores')->withPivot('score', 'note')->using(Score::class);
    }

    public function scoredStamps(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_scores')->using(Score::class)->withTimestamps();
    }

    /** The parent's created column is not selected: no date is proven. */
    public function scoredUnstamped(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_scores')->using(Score::class)->withPivot('note', 'updated_at');
    }

    public function plainScored(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_scores')->using(Pivot::class)->withPivot('rank', 'note', 'created_at');
    }

    public function datedScored(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_scores')->using(DatedScore::class)->withPivot('note', 'created_at');
    }

    public function convertingScored(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_scores')->using(ConvertingScore::class)->withPivot('note', 'created_at');
    }

    /** A `where()` may replace the relation: the slot is the class, and only its own types are proven. */
    public function hiddenScored(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_scores')->using(Score::class)->where('kind', 'x')->withPivot('score', 'flags', 'note');
    }

    public function foreignScored(): BelongsToMany
    {
        return (new Sheet())->belongsToMany(User::class, 'sheet_entries')->using(Score::class)->withPivot('hours');
    }

    public function scoredBase(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_scores');
    }

    /** Delegation: the outer `using()` and columns read the delegated relation's table. */
    public function delegatedScored(): BelongsToMany
    {
        return $this->scoredBase()->using(Score::class)->withPivot('score', 'note');
    }

    /** One branch is another model's factory: the refusal survives the merge of the returns. */
    public function branchedScored(bool $foreign): BelongsToMany
    {
        return $this->branchedScoredBase($foreign)->withPivot('note');
    }

    private function branchedScoredBase(bool $foreign): BelongsToMany
    {
        if ($foreign) {
            return (new Sheet())->belongsToMany(User::class, 'team_scores')->using(Score::class);
        }

        return $this->belongsToMany(User::class, 'team_scores')->using(Score::class);
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withPivot('weight');
    }
}
