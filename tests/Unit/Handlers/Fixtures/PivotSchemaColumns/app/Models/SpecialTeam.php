<?php

declare(strict_types=1);

namespace PivotSchemaFixture\Models;

/**
 * Inherits `Team::members()`, whose default joining table is computed on the receiver: `special_team_user`,
 * not `team_user`.
 */
class SpecialTeam extends Team {}
