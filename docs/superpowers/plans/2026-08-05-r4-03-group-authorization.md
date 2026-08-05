# R4-03 Group Authorization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace member-id-materializing group authorization in `mod_insightjournal` with group-id-based checks, so activity report, course report, and summary authorization scale with the requested page/chunk instead of course size.

**Architecture:** Three new small primitives in `locallib.php` (allowed group ids for the current user under an activity; a single-user existence check against a group-id set; a bounded-set membership filter against a group-id set) replace `insightjournal_current_user_groups()`/`insightjournal_current_user_group_userids()`, which fetch full member lists via `groups_get_all_groups(..., $withmembers = true)`. Each of the three call sites (activity report, course report, summary) is migrated to the new primitives one at a time, verified independently, and only once nothing references the old functions are they deleted.

**Tech Stack:** PHP 8.1+ (Moodle plugin), Moodle DB API (`$DB->get_in_or_equal()`, `record_exists_select()`, `get_fieldset_select()`), PHPUnit 9.6-11.5 (`advanced_testcase`), Behat/Selenium.

## Global Constraints

- Reference spec: `docs/superpowers/specs/2026-08-05-group-authorization-design.md` — every task's requirements implicitly include it.
- No behavior change for end users: every existing authorization outcome (who sees what) must be identical before and after. This is a performance refactor, not a feature change.
- `get_in_or_equal($ids, SQL_PARAMS_NAMED, 'grp', true, -1)` — the `-1` (`$onemptyitems`) must be preserved on every new group-id-based `IN()` clause that could receive an empty array, so an empty restriction produces "matches nobody" instead of a `coding_exception`.
- No PHPUnit query-count/memory-threshold assertions are added to the committed suite (user's explicit decision) — the load-test claim is proven via a one-off, non-committed benchmark script in Task 4.
- Toolchain per [[moodle-codechecker-toolchain]]/[[moodle-docker-phpunit-env]] memory: sync via `bash ~/sync-insightjournal.sh`, PHPUnit via `bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite`, phpcs via `"/mnt/c/xampp/php/php.exe" C:/Git/ij-tooling/vendor/squizlabs/php_codesniffer/bin/phpcs --standard=moodle,moodle-extra --extensions=php --warning-severity=1 <files>`, PHPStan via `bin/moodle-docker-compose exec webserver vendor/bin/phpstan analyse -c mod/insightjournal/phpstan.neon --memory-limit=1G --no-progress`, Behat via `bin/moodle-docker-compose exec webserver vendor/bin/behat --config /var/www/behatdata/behatrun/behat/behat.yml --tags @mod_insightjournal` (re-run `admin/tool/behat/cli/init.php` first if scenario files changed).
- TDD throughout: write/extend a failing test before touching production code, verify RED, implement, verify GREEN.

---

### Task 1: New locallib.php primitives + migrate the summary-visibility path

**Files:**
- Modify: `locallib.php:244-335` (the `insightjournal_current_user_groups()` block through `insightjournal_activity_visible_to_viewer()`)
- Test: `tests/locallib_groups_test.php`

**Interfaces:**
- Consumes: nothing new (only existing `groups_get_all_groups()`, `$DB` API, and the existing `insightjournal_activity_group_restricted()`).
- Produces (for Tasks 2 and 3):
  - `insightjournal_current_user_allowed_groupids(stdClass $course, cm_info|stdClass $cm): int[]`
  - `insightjournal_groupids_contain_member(array $groupids, int $userid): bool`
  - `insightjournal_groupids_members_among(array $groupids, array $userids): int[]`
- **Old `insightjournal_current_user_groups()` / `insightjournal_current_user_group_userids()` are NOT removed in this task** — `report.php` and `coursereport.php` still call `insightjournal_current_user_group_userids()` until Tasks 2/3 migrate them. Removing now would fatal-error those pages. Only `insightjournal_activity_visible_to_viewer()` (used solely by `summary.php`, entirely within `locallib.php`) is migrated in this task, since nothing outside `locallib.php` calls it directly.

- [ ] **Step 1: Write failing tests for the three new primitives**

Add to `tests/locallib_groups_test.php`, after the existing `test_group_userids_excludes_non_participating_group_when_cm_given` test (the last one before the `insightjournal_activity_visible_to_viewer` tests) — keep every existing test in the file untouched for now:

```php
    /**
     * Returns exactly the current user's own group ids for this activity's
     * grouping - not another group in the course they also belong to.
     */
    public function test_allowed_groupids_returns_own_groups(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);

        $this->setUser($teacher);

        $groupids = insightjournal_current_user_allowed_groupids($this->course, $this->cm);

        $this->assertEquals([(int) $groupa->id], $groupids);
        $this->assertNotContains((int) $groupb->id, $groupids);
    }

    /**
     * A user belonging to no groups gets an empty result, not an error -
     * callers must treat this as "matches nobody," never as "unrestricted."
     */
    public function test_allowed_groupids_empty_when_user_has_no_groups(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->setUser($teacher);

        $this->assertSame([], insightjournal_current_user_allowed_groupids($this->course, $this->cm));
    }

    /**
     * A falsy $USER->id (e.g. 0, the logged-out/guest sentinel) must still
     * produce "matches nobody," never silently fall through to
     * groups_get_all_groups() ignoring its userid filter.
     */
    public function test_allowed_groupids_empty_when_user_id_is_falsy(): void {
        $generator = $this->getDataGenerator();
        $student = $generator->create_and_enrol($this->course, 'student');
        $group = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);

        $this->setUser(0);

        $this->assertSame([], insightjournal_current_user_allowed_groupids($this->course, $this->cm));
    }

    /**
     * A grouping-scoped $cm restricts the returned group ids to only the
     * groups belonging to that grouping - a group in a different grouping
     * must never leak in, even if the current user also belongs to it.
     */
    public function test_allowed_groupids_scoped_to_activity_groupingid(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');

        $groupinga = $generator->create_grouping(['courseid' => $this->course->id]);
        $groupingb = $generator->create_grouping(['courseid' => $this->course->id]);
        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_grouping_group(['groupingid' => $groupinga->id, 'groupid' => $groupa->id]);
        $generator->create_grouping_group(['groupingid' => $groupingb->id, 'groupid' => $groupb->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $teacher->id]);

        $this->set_activity_grouping((int) $groupinga->id);
        $this->setUser($teacher);

        $groupids = insightjournal_current_user_allowed_groupids($this->course, $this->cm);

        $this->assertEquals([(int) $groupa->id], $groupids);
    }

    /**
     * A non-participating group is excluded even when it belongs to the
     * activity's own grouping.
     */
    public function test_allowed_groupids_excludes_non_participating_group(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');

        $grouping = $generator->create_grouping(['courseid' => $this->course->id]);
        $group = $generator->create_group([
            'courseid' => $this->course->id,
            'participation' => false,
        ]);
        $generator->create_grouping_group(['groupingid' => $grouping->id, 'groupid' => $group->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);

        $this->set_activity_grouping((int) $grouping->id);
        $this->setUser($teacher);

        $this->assertSame([], insightjournal_current_user_allowed_groupids($this->course, $this->cm));
    }

    /**
     * insightjournal_groupids_contain_member() finds a real member.
     */
    public function test_groupids_contain_member_true_when_member(): void {
        $generator = $this->getDataGenerator();
        $student = $generator->create_and_enrol($this->course, 'student');
        $group = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);

        $this->assertTrue(
            insightjournal_groupids_contain_member([(int) $group->id], (int) $student->id)
        );
    }

    /**
     * insightjournal_groupids_contain_member() correctly reports a
     * non-member as absent, not just "no error."
     */
    public function test_groupids_contain_member_false_when_not_member(): void {
        $generator = $this->getDataGenerator();
        $student = $generator->create_and_enrol($this->course, 'student');
        $other = $generator->create_and_enrol($this->course, 'student');
        $group = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);

        $this->assertFalse(
            insightjournal_groupids_contain_member([(int) $group->id], (int) $other->id)
        );
    }

    /**
     * An empty group id set never matches anyone - guards against a stray
     * unrestricted IN() clause.
     */
    public function test_groupids_contain_member_false_when_groupids_empty(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $this->assertFalse(insightjournal_groupids_contain_member([], (int) $student->id));
    }

    /**
     * insightjournal_groupids_members_among() returns exactly the subset of
     * the given userids that are members of the given groups - excluding a
     * course member who isn't in the candidate userid list at all, and one
     * who is in the list but not in any of the given groups.
     */
    public function test_groupids_members_among_filters_to_matching_userids(): void {
        $generator = $this->getDataGenerator();
        $ingroupinlist = $generator->create_and_enrol($this->course, 'student');
        $ingroupnotinlist = $generator->create_and_enrol($this->course, 'student');
        $inlistnotingroup = $generator->create_and_enrol($this->course, 'student');
        $group = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $ingroupinlist->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $ingroupnotinlist->id]);

        $result = insightjournal_groupids_members_among(
            [(int) $group->id],
            [(int) $ingroupinlist->id, (int) $inlistnotingroup->id]
        );

        $this->assertEquals([(int) $ingroupinlist->id], $result);
    }

    /**
     * A user in two of the given groups is returned only once.
     */
    public function test_groupids_members_among_deduplicates(): void {
        $generator = $this->getDataGenerator();
        $student = $generator->create_and_enrol($this->course, 'student');
        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $student->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $student->id]);

        $result = insightjournal_groupids_members_among(
            [(int) $groupa->id, (int) $groupb->id],
            [(int) $student->id]
        );

        $this->assertEquals([(int) $student->id], $result);
    }

    /**
     * Empty groupids or empty userids short-circuit to an empty result -
     * no query should be built with an empty IN() list.
     */
    public function test_groupids_members_among_empty_inputs(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);

        $this->assertSame([], insightjournal_groupids_members_among([], [(int) $student->id]));
        $this->assertSame([], insightjournal_groupids_members_among([(int) $group->id], []));
    }
```

Also update the `#[CoversFunction(...)]` block at the top of the class (currently listing `insightjournal_current_user_group_userids` alongside four others) to additionally list the three new functions:

```php
#[CoversFunction('insightjournal_activity_group_restricted')]
#[CoversFunction('insightjournal_current_user_group_userids')]
#[CoversFunction('insightjournal_current_user_allowed_groupids')]
#[CoversFunction('insightjournal_groupids_contain_member')]
#[CoversFunction('insightjournal_groupids_members_among')]
#[CoversFunction('insightjournal_activity_visible_to_viewer')]
#[CoversFunction('insightjournal_visible_activities_for_user')]
#[CoversFunction('insightjournal_coursereport_restrict_groupids')]
```

- [ ] **Step 2: Sync and run the new tests to verify they fail correctly**

```bash
bash ~/sync-insightjournal.sh
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite --filter "test_allowed_groupids|test_groupids_contain_member|test_groupids_members_among"
```
Expected: every one of these new tests **errors** with `Error: Call to undefined function insightjournal_current_user_allowed_groupids()` (or the equivalent for the other two functions) - not a normal assertion failure, since the functions don't exist yet. If any test passes or fails with a different error, stop and re-check the test itself before proceeding.

- [ ] **Step 3: Add the three new primitives to `locallib.php`**

Insert immediately after the closing `}` of `insightjournal_current_user_group_userids()` (currently ending at `locallib.php:303`), before the `insightjournal_activity_visible_to_viewer()` docblock:

```php
/**
 * Group ids the current user belongs to for a specific activity, per
 * Moodle's Separate Groups rules.
 *
 * Same $cm-scoping contract as insightjournal_current_user_groups() (only
 * groups in $cm->groupingid, participation-eligible only), but never
 * materialises member lists: groups_get_all_groups() is called with
 * $withmembers = false and $fields = 'g.id', so the underlying query only
 * ever fetches group ids, regardless of how large any group is.
 *
 * @param stdClass $course The course to look up group membership in.
 * @param cm_info|stdClass $cm The activity to scope the search to.
 * @return int[] Group ids.
 */
function insightjournal_current_user_allowed_groupids(stdClass $course, cm_info|stdClass $cm): array {
    global $USER;

    // Same falsy-$USER->id guard as insightjournal_current_user_groups() and
    // for the same reason: groups_get_all_groups() only applies its userid
    // filter when $userid is non-empty.
    if (empty($USER->id)) {
        return [];
    }

    $groups = groups_get_all_groups($course->id, $USER->id, (int) $cm->groupingid, 'g.id', false, true);
    return array_map('intval', array_keys($groups));
}

/**
 * Whether $userid is a member of any group in $groupids.
 *
 * A single existence query against groups_members, bounded by
 * count($groupids) - never by any group's member count. Intended for a
 * single-target-user check (e.g. summary.php's "is this one learner
 * visible to me") instead of materialising every allowed group's full
 * membership just to run in_array() over it.
 *
 * @param int[] $groupids Candidate group ids.
 * @param int $userid The user to check.
 * @return bool
 */
function insightjournal_groupids_contain_member(array $groupids, int $userid): bool {
    global $DB;

    if (empty($groupids) || empty($userid)) {
        return false;
    }

    [$insql, $params] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'grp');
    $params['userid'] = $userid;
    return $DB->record_exists_select('groups_members', "userid = :userid AND groupid $insql", $params);
}

/**
 * The subset of $userids that are members of any group in $groupids.
 *
 * A single groups_members query bounded by count($groupids) x
 * count($userids) - never by full course or group size. Intended for a
 * caller that already has a small, page/chunk-bounded candidate userid
 * list (e.g. coursereport.php's current screen page or CSV chunk), never
 * the whole course's participants at once.
 *
 * @param int[] $groupids Candidate group ids.
 * @param int[] $userids Candidate user ids to filter down.
 * @return int[] The subset of $userids found in any of $groupids.
 */
function insightjournal_groupids_members_among(array $groupids, array $userids): array {
    global $DB;

    if (empty($groupids) || empty($userids)) {
        return [];
    }

    [$ginsql, $gparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'grp');
    [$uinsql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
    $ids = $DB->get_fieldset_select(
        'groups_members',
        'DISTINCT userid',
        "groupid $ginsql AND userid $uinsql",
        array_merge($gparams, $uparams)
    );
    return array_map('intval', $ids);
}
```

- [ ] **Step 4: Run the new tests again to verify they pass**

```bash
bash ~/sync-insightjournal.sh
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite --filter "test_allowed_groupids|test_groupids_contain_member|test_groupids_members_among"
```
Expected: all pass (11 tests).

- [ ] **Step 5: Migrate `insightjournal_activity_visible_to_viewer()` to the new primitives**

The four existing tests covering this function
(`test_activity_visible_when_not_restricted`,
`test_activity_visible_when_target_in_viewer_group`,
`test_activity_not_visible_when_target_only_in_different_grouping`, and
`test_visible_activities_filters_to_authorized_only`, which exercises it
indirectly via `insightjournal_visible_activities_for_user()`) already exist
and test observable behavior - do not add new tests for this step, but do
not skip re-running them either (Step 6 covers this).

Change `locallib.php:324-335` from:

```php
function insightjournal_activity_visible_to_viewer(
    context_module $context,
    stdClass $course,
    cm_info|stdClass $cm,
    int $targetuserid
): bool {
    if (!insightjournal_activity_group_restricted($context, $course, $cm)) {
        return true;
    }

    return in_array($targetuserid, insightjournal_current_user_group_userids($course, $cm), true);
}
```

to:

```php
function insightjournal_activity_visible_to_viewer(
    context_module $context,
    stdClass $course,
    cm_info|stdClass $cm,
    int $targetuserid
): bool {
    if (!insightjournal_activity_group_restricted($context, $course, $cm)) {
        return true;
    }

    return insightjournal_groupids_contain_member(
        insightjournal_current_user_allowed_groupids($course, $cm),
        $targetuserid
    );
}
```

- [ ] **Step 6: Run the full `locallib_groups_test.php` file to verify nothing regressed**

```bash
bash ~/sync-insightjournal.sh
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite --filter locallib_groups_test
```
Expected: every test in the file passes, including the pre-existing
`insightjournal_current_user_group_userids`/`insightjournal_activity_visible_to_viewer`/`insightjournal_visible_activities_for_user` tests (untouched, still exercising the still-present old function and the newly migrated one).

- [ ] **Step 7: phpcs and PHPStan on the changed file**

```bash
"/mnt/c/xampp/php/php.exe" C:/Git/ij-tooling/vendor/squizlabs/php_codesniffer/bin/phpcs --standard=moodle,moodle-extra --extensions=php --warning-severity=1 C:/Git/insightjournal/locallib.php C:/Git/insightjournal/tests/locallib_groups_test.php
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpstan analyse -c mod/insightjournal/phpstan.neon --memory-limit=1G --no-progress
```
Expected: both exit 0 / "No errors."

- [ ] **Step 8: Commit**

```bash
git add locallib.php tests/locallib_groups_test.php
git commit -m "$(cat <<'EOF'
feat: add group-id-based authorization primitives (R4-03 part 1/4)

insightjournal_current_user_groups()/insightjournal_current_user_group_userids()
fetch every allowed group's full member list even when a caller only
needs the group ids or a single membership check. Add three narrower
primitives that never materialise member lists, and migrate the one
purely-internal caller (insightjournal_activity_visible_to_viewer(),
summary.php's visibility path) to them. report.php and coursereport.php
still use the old functions until the next two tasks migrate them - the
old functions are not removed yet.
EOF
)"
```

---

### Task 2: Migrate the activity report (report.php + report_table.php)

**Files:**
- Modify: `report.php:45-46`
- Modify: `classes/table/report_table.php:73-84,122-126`
- Modify: `tests/report_authorization_test.php:106-107` (reproduces report.php's real wiring - must change in lockstep)

**Interfaces:**
- Consumes: `insightjournal_current_user_allowed_groupids()` from Task 1.
- Produces: `report_table`'s constructor now takes `?array $restrictgroupids` instead of `?array $restrictuserids` - Task 3 does not depend on this, but any future caller of `report_table` must use the new parameter name/semantics.

- [ ] **Step 1: Update `report_authorization_test.php`'s production-wiring reproduction (this IS the failing-test step for this task)**

This test file's whole purpose (per its own docblock) is to reproduce
report.php's exact call sequence, so its existing three tests
(`test_teacher_without_accessallgroups_sees_only_own_group`,
`test_manager_with_accessallgroups_sees_every_group`,
`test_two_groupings_and_a_non_participating_group`) are the regression
guard - update the helper they all call through, then watch them fail
against the still-unmodified `report_table.php`.

Change `tests/report_authorization_test.php:106-108` from:

```php
        $restrictuserids = insightjournal_activity_group_restricted($this->context, $this->course, $this->cm)
            ? insightjournal_current_user_group_userids($this->course, $this->cm)
            : null;

        $table = new report_table(
            'report_authorization_test',
            $this->course,
            $this->cm,
            $this->diary,
            $this->context,
            '',
            $restrictuserids
        );
```

to:

```php
        $restrictgroupids = insightjournal_activity_group_restricted($this->context, $this->course, $this->cm)
            ? insightjournal_current_user_allowed_groupids($this->course, $this->cm)
            : null;

        $table = new report_table(
            'report_authorization_test',
            $this->course,
            $this->cm,
            $this->diary,
            $this->context,
            '',
            $restrictgroupids
        );
```

- [ ] **Step 2: Sync and run to verify RED**

```bash
bash ~/sync-insightjournal.sh
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite --filter report_authorization_test
```
Expected: `test_teacher_without_accessallgroups_sees_only_own_group` and
`test_two_groupings_and_a_non_participating_group` **fail** (not error) -
`report_table` still builds `u.id IN (...)` against values that are now
group ids, not user ids, so the WHERE clause no longer matches the
expected rows. `test_manager_with_accessallgroups_sees_every_group` should
still pass (it passes `null`, untouched by this change). If any test
errors instead of failing, or the manager test also breaks, stop and
investigate before proceeding.

- [ ] **Step 3: Update `report_table.php`'s constructor and WHERE-clause construction**

Change the docblock and parameter at `report_table.php:73-84` from:

```php
     * @param ?array $restrictuserids When not null, only these userids' entries
     *     are included (an empty array means "match nobody," not "no
     *     restriction") - used to enforce Moodle's Separate Groups mode.
     */
    public function __construct(
        string $uniqueid,
        stdClass $course,
        stdClass $cm,
        stdClass $diary,
        context_module $context,
        string $search = '',
        ?array $restrictuserids = null
    ) {
```

to:

```php
     * @param ?array $restrictgroupids When not null, only entries from
     *     members of these group ids are included (an empty array means
     *     "match nobody," not "no restriction") - used to enforce Moodle's
     *     Separate Groups mode.
     */
    public function __construct(
        string $uniqueid,
        stdClass $course,
        stdClass $cm,
        stdClass $diary,
        context_module $context,
        string $search = '',
        ?array $restrictgroupids = null
    ) {
```

Change the WHERE-clause construction at `report_table.php:122-126` from:

```php
        if ($restrictuserids !== null) {
            [$rinsql, $rparams] = $DB->get_in_or_equal($restrictuserids, SQL_PARAMS_NAMED, 'grp', true, -1);
            $where .= ' AND u.id ' . $rinsql;
            $params = array_merge($params, $rparams);
        }
```

to:

```php
        if ($restrictgroupids !== null) {
            [$ginsql, $gparams] = $DB->get_in_or_equal($restrictgroupids, SQL_PARAMS_NAMED, 'grp', true, -1);
            $where .= ' AND EXISTS (
                SELECT 1 FROM {groups_members} gm
                 WHERE gm.userid = u.id AND gm.groupid ' . $ginsql . '
            )';
            $params = array_merge($params, $gparams);
        }
```

- [ ] **Step 4: Update `report.php`**

Change `report.php:45-46` from:

```php
$restrictuserids = insightjournal_activity_group_restricted($context, $course, $cm)
    ? insightjournal_current_user_group_userids($course, $cm)
    : null;
```

to:

```php
$restrictgroupids = insightjournal_activity_group_restricted($context, $course, $cm)
    ? insightjournal_current_user_allowed_groupids($course, $cm)
    : null;
```

And update the `report_table` construction call site (currently at
`report.php:54-62`, the last constructor argument) from `$restrictuserids`
to `$restrictgroupids`.

- [ ] **Step 5: Sync and run to verify GREEN**

```bash
bash ~/sync-insightjournal.sh
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite --filter report_authorization_test
```
Expected: all three tests pass.

- [ ] **Step 6: Run the full PHPUnit suite (this task touches a shared class other tests may construct)**

```bash
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite
```
Expected: no new failures (check `tests/table/` and any `report_table_test.php` in particular).

- [ ] **Step 7: phpcs and PHPStan**

```bash
"/mnt/c/xampp/php/php.exe" C:/Git/ij-tooling/vendor/squizlabs/php_codesniffer/bin/phpcs --standard=moodle,moodle-extra --extensions=php --warning-severity=1 C:/Git/insightjournal/report.php C:/Git/insightjournal/classes/table/report_table.php C:/Git/insightjournal/tests/report_authorization_test.php
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpstan analyse -c mod/insightjournal/phpstan.neon --memory-limit=1G --no-progress
```
Expected: both clean.

- [ ] **Step 8: Behat sanity check for this surface**

```bash
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/behat --config /var/www/behatdata/behatrun/behat/behat.yml --tags @mod_insightjournal --name "Separate Groups"
```
Expected: the activity-report Separate Groups scenario (`A teacher restricted to Separate Groups only sees their own group's entries in the activity report`) passes.

- [ ] **Step 9: Commit**

```bash
git add report.php classes/table/report_table.php tests/report_authorization_test.php
git commit -m "$(cat <<'EOF'
fix: filter the activity report via groups_members, not a userid list (R4-03 part 2/4)

report_table built u.id IN (...) from every allowed group's full
member list, so the parameter count and the upfront authorization
work both scaled with group size, not with the paginated result set.
Filter via an EXISTS subquery against groups_members and group ids
instead - parameter count now scales with the (small, grouping-bounded)
number of allowed groups.
EOF
)"
```

---

### Task 3: Migrate the course report (coursereport.php)

**Files:**
- Modify: `locallib.php` (add two new functions; modify `insightjournal_coursereport_restrict_groupids()`)
- Modify: `coursereport.php:55-84,136,199`
- Test: `tests/coursereport_authorization_test.php`

**Interfaces:**
- Consumes: `insightjournal_current_user_allowed_groupids()`, `insightjournal_groupids_members_among()` from Task 1.
- Produces:
  - `insightjournal_coursereport_allowed_groupids_by_diary(array $activities, stdClass $course): array` — `array<int, int[]|null>`, instance id => allowed group ids (or `null` for "not restricted for the current viewer"). Resolves once per distinct `groupingid`, not once per activity.
  - `insightjournal_coursereport_diary_allowed_users(array $diaryallowedgroupids, array $userids): array` — `array<int, array<int, bool>|null>`, instance id => (`userid => true`) map scoped to exactly `$userids`, or `null` when unrestricted. Consumes the previous function's output.

- [ ] **Step 1: Write failing tests for the two new coursereport helpers**

Add to `tests/coursereport_authorization_test.php`, after the existing
`test_two_layer_authorization_across_two_groupings` test. **Note:** this
class's `setUp()` only creates `$this->course` - there is no shared
`$this->diary`/`$this->cm` fixture; every test (including the existing
one) creates its own module(s) locally, matching the pattern below:

```php
    /**
     * Two activities sharing a grouping resolve to the same allowed group
     * ids for the same viewer - the per-groupingid cache must not corrupt
     * the result for either activity.
     */
    public function test_allowed_groupids_by_diary_shares_result_across_shared_grouping(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');

        $diarya = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $diaryb = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cma = get_coursemodule_from_id('insightjournal', $diarya->cmid, 0, false, MUST_EXIST);
        $cmb = get_coursemodule_from_id('insightjournal', $diaryb->cmid, 0, false, MUST_EXIST);

        $grouping = $generator->create_grouping(['courseid' => $this->course->id]);
        $group = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_grouping_group(['groupingid' => $grouping->id, 'groupid' => $group->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);

        $DB->set_field('course_modules', 'groupingid', $grouping->id, ['id' => $cma->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cma->id]);
        $DB->set_field('course_modules', 'groupingid', $grouping->id, ['id' => $cmb->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cmb->id]);
        $cma = get_coursemodule_from_id('insightjournal', $diarya->cmid, 0, false, MUST_EXIST);
        $cmb = get_coursemodule_from_id('insightjournal', $diaryb->cmid, 0, false, MUST_EXIST);

        $this->setUser($teacher);

        $result = insightjournal_coursereport_allowed_groupids_by_diary(
            [$diarya->id => $cma, $diaryb->id => $cmb],
            $this->course
        );

        $this->assertEquals([(int) $group->id], $result[$diarya->id]);
        $this->assertEquals([(int) $group->id], $result[$diaryb->id]);
    }

    /**
     * An activity that isn't group-restricted for the current viewer maps
     * to null - the "unrestricted" marker, not an empty group id array
     * (which would incorrectly mean "restricted, but matches nobody").
     */
    public function test_allowed_groupids_by_diary_null_when_not_restricted(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);

        $this->setUser($teacher);

        $result = insightjournal_coursereport_allowed_groupids_by_diary(
            [$diary->id => $cm],
            $this->course
        );

        $this->assertNull($result[$diary->id]);
    }

    /**
     * insightjournal_coursereport_diary_allowed_users() scopes its result to
     * exactly the given userids - a course member who belongs to an allowed
     * group but isn't in the candidate list at all must not appear. Uses a
     * plain int as the "diary id" key throughout, since this function does
     * no DB lookup tied to diary identity - it only maps over its inputs.
     */
    public function test_diary_allowed_users_scoped_to_given_userids(): void {
        $generator = $this->getDataGenerator();
        $ingroupinlist = $generator->create_and_enrol($this->course, 'student');
        $ingroupnotinlist = $generator->create_and_enrol($this->course, 'student');
        $group = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $ingroupinlist->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $ingroupnotinlist->id]);

        $result = insightjournal_coursereport_diary_allowed_users(
            [1 => [(int) $group->id]],
            [(int) $ingroupinlist->id]
        );

        $this->assertEquals([(int) $ingroupinlist->id => true], $result[1]);
    }

    /**
     * A null entry (unrestricted activity) passes through as null, never as
     * an empty map.
     */
    public function test_diary_allowed_users_passes_through_null(): void {
        $result = insightjournal_coursereport_diary_allowed_users(
            [1 => null],
            [1, 2, 3]
        );

        $this->assertNull($result[1]);
    }
```

- [ ] **Step 2: Sync and run to verify RED**

```bash
bash ~/sync-insightjournal.sh
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite --filter "test_allowed_groupids_by_diary|test_diary_allowed_users"
```
Expected: all four new tests **error** with `Call to undefined function`.

- [ ] **Step 3: Add the two new functions to `locallib.php`**

Insert after `insightjournal_visible_activities_for_user()` (currently
ending at `locallib.php:356`), before the
`insightjournal_coursereport_restrict_groupids()` docblock:

```php
/**
 * Allowed group ids for the current viewer, keyed by insightjournal
 * instance id, for a set of activities - resolved once per distinct
 * groupingid rather than once per activity, since two activities sharing
 * a grouping always resolve to the same allowed group ids for a given
 * viewer (insightjournal_current_user_allowed_groupids() depends only on
 * $course and $cm->groupingid).
 *
 * @param cm_info[]|stdClass[] $activities Activities keyed by instance id.
 * @param stdClass $course The course the activities belong to.
 * @return array Instance id => allowed group ids (int[]), or null for an
 *     activity that is not group-restricted for the current viewer.
 */
function insightjournal_coursereport_allowed_groupids_by_diary(array $activities, stdClass $course): array {
    $bygroupingid = [];
    $result = [];
    foreach ($activities as $diaryid => $cm) {
        $context = context_module::instance($cm->id);
        if (!insightjournal_activity_group_restricted($context, $course, $cm)) {
            $result[$diaryid] = null;
            continue;
        }
        $groupingid = (int) $cm->groupingid;
        if (!array_key_exists($groupingid, $bygroupingid)) {
            $bygroupingid[$groupingid] = insightjournal_current_user_allowed_groupids($course, $cm);
        }
        $result[$diaryid] = $bygroupingid[$groupingid];
    }

    return $result;
}

/**
 * Per-diary "is this userid visible under this diary's group restriction"
 * lookup maps, scoped to exactly $userids - the current screen page or CSV
 * chunk, never the whole course.
 *
 * @param array $diaryallowedgroupids From
 *     insightjournal_coursereport_allowed_groupids_by_diary() - instance id
 *     => allowed group ids, or null for "not restricted."
 * @param int[] $userids The userids actually present in this page/chunk.
 * @return array Instance id => (userid => true) map, or null when the
 *     diary is unrestricted for the current viewer.
 */
function insightjournal_coursereport_diary_allowed_users(array $diaryallowedgroupids, array $userids): array {
    $result = [];
    foreach ($diaryallowedgroupids as $diaryid => $groupids) {
        $result[$diaryid] = $groupids === null
            ? null
            : array_flip(insightjournal_groupids_members_among($groupids, $userids));
    }

    return $result;
}
```

- [ ] **Step 4: Run the new tests again to verify GREEN**

```bash
bash ~/sync-insightjournal.sh
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite --filter "test_allowed_groupids_by_diary|test_diary_allowed_users"
```
Expected: all four pass.

- [ ] **Step 5: Migrate `insightjournal_coursereport_restrict_groupids()`**

Change `locallib.php:386` (inside the existing function, the
`groups_get_all_groups`-backed call) from:

```php
        $groupids = array_merge($groupids, array_keys(insightjournal_current_user_groups($course, $cm)));
```

to:

```php
        $groupids = array_merge($groupids, insightjournal_current_user_allowed_groupids($course, $cm));
```

Run the existing `coursereport_authorization_test.php` test
(`test_two_layer_authorization_across_two_groupings`, which calls this
function directly) to confirm this one-line change alone is still green:

```bash
bash ~/sync-insightjournal.sh
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite --filter coursereport_authorization_test
```
Expected: all tests in the file pass (including the four new ones from Step 4).

- [ ] **Step 6: Restructure `coursereport.php`'s `$diaryallowedusers` computation**

Change `coursereport.php:55-84` from:

```php
// The insightjournal_coursereport_restrict_groupids() call already returns null for
// "no restriction needed" (at least one visible activity is unrestricted); the
// falsy-$USER->id guard now lives centrally in insightjournal_current_user_groups().
// The bare "if ($groupids)" check inside get_enrolled_users()/count_enrolled_users()
// would otherwise treat an empty array the SAME as null ("no filter"), so
// $blockallparticipants still catches "every visible activity is restricted, but
// the union of the viewer's own allowed groups is empty" explicitly, before that
// ambiguity can matter.
$restrictgroupids = insightjournal_coursereport_restrict_groupids($activities, $course);
$blockallparticipants = $restrictgroupids !== null && empty($restrictgroupids);
if ($restrictgroupids === null) {
    $restrictgroupids = 0;
}

$diaryids = array_keys($activities);
$diaries = $DB->get_records_list('insightjournal', 'id', $diaryids, 'id ASC');
// Precomputed once per activity (not per participant): insightjournal_activity_group_restricted()
// and insightjournal_current_user_group_userids() do uncached DB queries depending only on
// ($course, $cm), never on the participant being checked - calling them inside the participant
// loops below would repeat identical queries once per participant per restricted activity. null
// means "no restriction, every participant is visible under this activity"; otherwise a
// userid => true map for O(1) per-cell lookups.
$diaryallowedusers = [];
foreach ($diaries as $diary) {
    $cm = $activities[$diary->id];
    $context = context_module::instance($cm->id);
    $diaryallowedusers[$diary->id] = insightjournal_activity_group_restricted($context, $course, $cm)
        ? array_flip(insightjournal_current_user_group_userids($course, $cm))
        : null;
}
```

to:

```php
// The insightjournal_coursereport_restrict_groupids() call already returns null for
// "no restriction needed" (at least one visible activity is unrestricted); the
// falsy-$USER->id guard now lives centrally in insightjournal_current_user_allowed_groupids().
// The bare "if ($groupids)" check inside get_enrolled_users()/count_enrolled_users()
// would otherwise treat an empty array the SAME as null ("no filter"), so
// $blockallparticipants still catches "every visible activity is restricted, but
// the union of the viewer's own allowed groups is empty" explicitly, before that
// ambiguity can matter.
$restrictgroupids = insightjournal_coursereport_restrict_groupids($activities, $course);
$blockallparticipants = $restrictgroupids !== null && empty($restrictgroupids);
if ($restrictgroupids === null) {
    $restrictgroupids = 0;
}

$diaryids = array_keys($activities);
$diaries = $DB->get_records_list('insightjournal', 'id', $diaryids, 'id ASC');
// Allowed group ids per diary (R4-03): resolved once per distinct grouping,
// not once per activity, and NOT the member lookup itself - that happens
// below, scoped to only the userids in the current page/CSV chunk, never
// the whole course at once.
$diaryallowedgroupids = insightjournal_coursereport_allowed_groupids_by_diary($activities, $course);
```

Change the CSV-chunk loop at `coursereport.php:133-149` — insert the
per-chunk resolution right after `$chunkentries` is computed, and use it
in place of the old course-wide `$diaryallowedusers`:

```php
        $chunkentries = insightjournal_entries_by_diary_and_user($diaryids, array_keys($chunk));
        $diaryallowedusers = insightjournal_coursereport_diary_allowed_users(
            $diaryallowedgroupids,
            array_keys($chunk)
        );
        foreach ($chunk as $user) {
```
(the rest of the loop body, starting at `foreach ($diaries as $diary) {`, is unchanged).

Change the on-screen path — insert the per-page resolution right after
`$entries` is computed (currently `coursereport.php:173`):

```php
$entries = insightjournal_entries_by_diary_and_user($diaryids, array_keys($participants));
$diaryallowedusers = insightjournal_coursereport_diary_allowed_users(
    $diaryallowedgroupids,
    array_keys($participants)
);
```
(the render loop below, starting at `foreach ($participants as $user) {`, is unchanged - it already reads `$diaryallowedusers[$diary->id]`).

- [ ] **Step 7: Sync and run the full PHPUnit suite**

```bash
bash ~/sync-insightjournal.sh
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite
```
Expected: no failures anywhere in the suite (`coursereport_authorization_test.php` and `coursereport_csv_test.php` in particular, since they exercise the functions this task touches).

- [ ] **Step 8: phpcs and PHPStan**

```bash
"/mnt/c/xampp/php/php.exe" C:/Git/ij-tooling/vendor/squizlabs/php_codesniffer/bin/phpcs --standard=moodle,moodle-extra --extensions=php --warning-severity=1 C:/Git/insightjournal/locallib.php C:/Git/insightjournal/coursereport.php C:/Git/insightjournal/tests/coursereport_authorization_test.php
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpstan analyse -c mod/insightjournal/phpstan.neon --memory-limit=1G --no-progress
```
Expected: both clean.

- [ ] **Step 9: Full Behat suite — required, not optional**

The inline restructuring in Step 6 (moving `$diaryallowedusers` computation
from before pagination to inside each loop) has no PHPUnit coverage of its
own beyond the two new locallib helper functions - `coursereport.php`'s
page script itself is not unit-testable without the R4-04 service
extraction (out of scope here). The two Behat scenarios covering
`coursereport.php`'s actual Separate Groups masking end-to-end
(`A teacher restricted to Separate Groups only sees their own group's
participants in the course-wide report` and `A teacher restricted to
Separate Groups never sees a different activity's grouping data in the
course report`) are the real regression net for this step - run the full
suite, not a filtered subset:

```bash
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver php admin/tool/behat/cli/init.php
bin/moodle-docker-compose exec webserver vendor/bin/behat --config /var/www/behatdata/behatrun/behat/behat.yml --tags @mod_insightjournal
```
Expected: 20/20 scenarios pass.

- [ ] **Step 10: Commit**

```bash
git add locallib.php coursereport.php tests/coursereport_authorization_test.php
git commit -m "$(cat <<'EOF'
fix: resolve course-report group masking per page/chunk, not per course (R4-03 part 3/4)

$diaryallowedusers materialised every allowed group's full membership
for the whole course, once per activity, before pagination - independent
of whether 20 rows or 5000 were ever rendered. Split into
insightjournal_coursereport_allowed_groupids_by_diary() (group ids only,
cached per grouping) and insightjournal_coursereport_diary_allowed_users()
(membership resolved only for the current page's/CSV chunk's userids),
called inside both loops instead of once upfront.
EOF
)"
```

---

### Task 4: Remove dead primitives, full verification, benchmark, docs

**Files:**
- Modify: `locallib.php` (delete `insightjournal_current_user_groups()`, `insightjournal_current_user_group_userids()`)
- Modify: `tests/locallib_groups_test.php` (delete their tests)
- Modify: `CHANGELOG.md`, `Fix.md`
- Temporary, NOT committed: a benchmark PHPUnit test file

**Interfaces:**
- Consumes: everything from Tasks 1-3.
- Produces: nothing further consumes this task's output - it's the final cleanup/verification task.

- [ ] **Step 1: Confirm the two old functions have no remaining callers**

```bash
grep -rn "insightjournal_current_user_groups(\|insightjournal_current_user_group_userids(" /mnt/c/Git/insightjournal --include="*.php"
```
Expected: only the two function *definitions* themselves in `locallib.php`, and their own doc-comment cross-references (e.g. `insightjournal_current_user_group_userids()`'s docblock references `insightjournal_current_user_groups()`). If anything else shows up, stop - Tasks 2/3 missed a call site.

- [ ] **Step 2: Delete the two functions from `locallib.php`**

Delete `locallib.php:244-303` in full (the `insightjournal_current_user_groups()` docblock+function through the `insightjournal_current_user_group_userids()` docblock+function, i.e. everything from `/**\n * Groups belonging to the current user...` through the closing `}` of `insightjournal_current_user_group_userids()`).

- [ ] **Step 3: Delete their tests from `tests/locallib_groups_test.php`**

Delete every test method whose body calls `insightjournal_current_user_groups(` or `insightjournal_current_user_group_userids(` directly:
`test_group_userids_returns_own_group_members`,
`test_group_userids_empty_when_user_has_no_groups`,
`test_group_userids_unions_multiple_groups`,
`test_group_userids_empty_when_user_id_is_falsy`,
`test_group_userids_scoped_to_activity_groupingid_when_cm_given`,
`test_group_userids_excludes_non_participating_group_when_cm_given`.

(Every other test in the file - the `insightjournal_activity_group_restricted` tests, the new Task 1 tests, the `insightjournal_activity_visible_to_viewer`/`insightjournal_visible_activities_for_user`/`insightjournal_coursereport_restrict_groupids` tests - stays.)

Remove `#[CoversFunction('insightjournal_current_user_group_userids')]` from the class docblock (the three new-primitive `#[CoversFunction(...)]` entries added in Task 1 stay).

- [ ] **Step 4: Sync and run the full suite**

```bash
bash ~/sync-insightjournal.sh
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite
```
Expected: all green, no undefined-function errors.

- [ ] **Step 5: phpcs, PHPStan, full Behat**

```bash
"/mnt/c/xampp/php/php.exe" C:/Git/ij-tooling/vendor/squizlabs/php_codesniffer/bin/phpcs --standard=moodle,moodle-extra --extensions=php --warning-severity=1 C:/Git/insightjournal/locallib.php C:/Git/insightjournal/tests/locallib_groups_test.php
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpstan analyse -c mod/insightjournal/phpstan.neon --memory-limit=1G --no-progress
bin/moodle-docker-compose exec webserver vendor/bin/behat --config /var/www/behatdata/behatrun/behat/behat.yml --tags @mod_insightjournal
```
Expected: all clean/green.

- [ ] **Step 6: One-off benchmark (not committed)**

Create `tests/_r4_03_benchmark_test.php` in the **moodle-dev copy only**
(`~/moodle-dev/moodle/mod/insightjournal/tests/`, do NOT create this file
under `/mnt/c/Git/insightjournal` - it must never be synced back or
committed):

```php
<?php
declare(strict_types=1);

namespace mod_insightjournal;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');

/**
 * One-off R4-03 before/after benchmark - NOT part of the committed suite.
 * Run manually against main and against the fix branch with an identical
 * seed, compare the printed numbers. Delete after use.
 */
final class r4_03_benchmark_test extends advanced_testcase {
    /**
     * @dataProvider member_count_provider
     */
    public function test_coursereport_group_resolution_scales_with_chunk(int $membercount): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $grouping = $generator->create_grouping(['courseid' => $course->id]);
        $groupcount = max(1, (int) ceil($membercount / 50));
        $groupids = [];
        for ($i = 0; $i < $groupcount; $i++) {
            $group = $generator->create_group(['courseid' => $course->id]);
            $generator->create_grouping_group(['groupingid' => $grouping->id, 'groupid' => $group->id]);
            $groupids[] = (int) $group->id;
        }

        $teacher = $generator->create_and_enrol($course, 'teacher');
        $generator->create_group_member(['groupid' => $groupids[0], 'userid' => $teacher->id]);

        $students = [];
        for ($i = 0; $i < $membercount; $i++) {
            $student = $generator->create_and_enrol($course, 'student');
            $generator->create_group_member(['groupid' => $groupids[$i % $groupcount], 'userid' => $student->id]);
            $students[] = (int) $student->id;
        }

        $diary = $generator->create_module('insightjournal', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $DB->set_field('course_modules', 'groupingid', $grouping->id, ['id' => $cm->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cm->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);

        $this->setUser($teacher);
        $activities = [$diary->id => $cm];

        $readsbefore = $DB->perf_get_reads();
        $memorybefore = memory_get_peak_usage(true);
        $start = microtime(true);

        // Mirrors coursereport.php's real sequence: resolve once, then
        // resolve per-chunk twice (simulating a 20-row screen page and a
        // 500-row CSV chunk) against the SAME already-resolved group ids.
        $restrictgroupids = insightjournal_coursereport_restrict_groupids($activities, $course);
        $diaryallowedgroupids = insightjournal_coursereport_allowed_groupids_by_diary($activities, $course);
        $page = array_slice($students, 0, 20);
        $chunk = array_slice($students, 0, min(500, $membercount));
        insightjournal_coursereport_diary_allowed_users($diaryallowedgroupids, $page);
        insightjournal_coursereport_diary_allowed_users($diaryallowedgroupids, $chunk);

        $elapsed = microtime(true) - $start;
        $reads = $DB->perf_get_reads() - $readsbefore;
        $memory = memory_get_peak_usage(true) - $memorybefore;

        fwrite(STDERR, sprintf(
            "\n[R4-03 BENCHMARK] members=%d groups=%d elapsed=%.4fs reads=%d peak_mem_delta=%d bytes\n",
            $membercount,
            $groupcount,
            $elapsed,
            $reads,
            $memory
        ));
        $this->assertTrue(true);
    }

    /**
     * @return array
     */
    public static function member_count_provider(): \Generator {
        yield '50 members' => [50];
        yield '500 members' => [500];
        yield '5000 members' => [5000];
    }
}
```

Run against the **current (fixed) code first**, then check out `main` in a
scratch worktree and re-sync to compare against the **pre-fix code**:

```bash
bash ~/sync-insightjournal.sh
cp /path/to/above/test.php ~/moodle-dev/moodle/mod/insightjournal/tests/_r4_03_benchmark_test.php
cd ~/moodle-dev/moodle-docker
bin/moodle-docker-compose exec webserver vendor/bin/phpunit --filter r4_03_benchmark_test mod/insightjournal/tests/_r4_03_benchmark_test.php
# Note the three printed lines (50/500/5000 members).

# Then, in /mnt/c/Git/insightjournal, temporarily check out the commit
# BEFORE Task 1 (the commit this plan started from), re-sync, copy the
# benchmark file back in (it doesn't exist pre-fix, so it's copied fresh
# each time, not part of the checkout), and re-run:
git log --oneline -1 # confirm current HEAD to return to afterward
git stash # if anything is uncommitted - should be clean at this point
git checkout <commit-before-task-1>
bash ~/sync-insightjournal.sh
cp /path/to/above/test.php ~/moodle-dev/moodle/mod/insightjournal/tests/_r4_03_benchmark_test.php
# but the benchmark calls insightjournal_coursereport_allowed_groupids_by_diary()
# and insightjournal_coursereport_diary_allowed_users(), which don't exist
# pre-fix - replace those three lines with the pre-fix equivalent:
#   $diaryallowedusers = array_flip(insightjournal_current_user_group_userids($course, $cm));
# (called once, matching the pre-fix "once per activity, course-wide" shape,
# not once per page/chunk - that IS the before/after difference being measured)
cd ~/moodle-dev/moodle-docker
bin/moodle-docker-compose exec webserver vendor/bin/phpunit --filter r4_03_benchmark_test mod/insightjournal/tests/_r4_03_benchmark_test.php
# Note the three printed lines again, then:
cd /mnt/c/Git/insightjournal && git checkout <original-branch>
rm ~/moodle-dev/moodle/mod/insightjournal/tests/_r4_03_benchmark_test.php
```

Xdebug must be off and OPcache on in the container (check
`~/moodle-dev/moodle-docker/local.yml`'s PHP ini overrides per
[[moodle-prod-v2-docker-env]] memory's pattern) - `~/moodle-dev` runs on
the Linux filesystem already (not `/mnt/c/...`), satisfying that part of
the measurement plan automatically.

Record the six numbers (before/after x 50/500/5000) in the Fix.md
implementation log in Step 8 below. Expected direction: `reads` and
`peak_mem_delta` roughly flat across 50/500/5000 after the fix (bounded by
grouping size + chunk size, not membercount), and growing roughly linearly
with membercount before the fix.

- [ ] **Step 7: Update CHANGELOG.md**

Add to the `### Changed` section under `## [Unreleased]` (same section
R4-02's entry lives in), as a new bullet:

```markdown
- **Group-based authorization (activity report, course report, summary)
  no longer materialises every allowed group's full member list**,
  addressing the R4-03 review finding. `insightjournal_current_user_groups()`
  and `insightjournal_current_user_group_userids()` fetched every member of
  every group a viewer could see, regardless of how much of that data any
  single request actually needed - a course report page rendering 20 rows
  still resolved membership for every group member in the course, once per
  activity, before pagination even started. Authorization now flows
  through group ids: the activity report filters via a `groups_members`
  existence subquery, summary checks a single target user via one
  existence query, and the course report resolves membership only for the
  userids on the current page or CSV chunk (with the allowed-group-ids
  lookup itself cached per grouping). No change to who sees what - this is
  a memory/query-scaling fix, not a behavior change.
```

- [ ] **Step 8: Update `Fix.md`**

Mark R4-03 done, following the same convention as R4-01/R4-02/CR-01
(`— ✅ Erledigt (2026-08-05, `main`, noch nicht gepusht)` initially, filled
in with the real commit hash in a follow-up commit after this task's main
commit lands), add an **Umsetzung** paragraph summarizing the three new
primitives + per-surface changes, and fill in the benchmark's six recorded
numbers under a **Verifiziert** line. Update the "Empfohlene
Umsetzungsreihenfolge" list entry for R4-03 to strikethrough+✅, matching
R4-01/R4-02/CR-01's existing entries.

- [ ] **Step 9: Commit**

```bash
git add locallib.php tests/locallib_groups_test.php CHANGELOG.md
git commit -m "$(cat <<'EOF'
fix: remove now-unused member-list group primitives (R4-03 part 4/4)

insightjournal_current_user_groups()/insightjournal_current_user_group_userids()
have no remaining caller now that the activity report, course report,
and summary paths all use the group-id-based primitives instead -
removing them closes the same kind of latent leak surface R4-02 already
closed for their $cm-optional legacy path.
EOF
)"
git add Fix.md
git commit -m "docs: mark R4-03 done in Fix.md"
```

## Self-Review Notes

- **Spec coverage:** All three surfaces (activity report, course report,
  summary) covered (Tasks 2, 3, 1-step-5 respectively). The `-1`
  `$onemptyitems` correctness detail flagged in the spec's self-review is
  carried into Task 2 Step 3. The per-grouping cache requirement is
  satisfied by `insightjournal_coursereport_allowed_groupids_by_diary()`
  in Task 3 (a refinement over the spec's inline-script pseudocode, made a
  proper testable function so Task 3 has real PHPUnit coverage beyond
  Behat - noted here since it wasn't spelled out this concretely in the
  spec itself). The benchmark/measurement plan is covered in Task 4 Step
  6, matching the user's chosen "one-off script, not committed" approach.
- **Placeholder scan:** No TBD/TODO; the benchmark step's "before" run
  requires substituting one line by hand (documented exactly what to
  substitute) since the pre-fix function doesn't exist to call directly -
  this is inherent to a before/after comparison across an API change, not
  a missing detail.
- **Type consistency:** `insightjournal_current_user_allowed_groupids()`
  (Task 1) is consumed with identical signature in Tasks 2 and 3.
  `insightjournal_groupids_members_among()` (Task 1) is consumed by
  `insightjournal_coursereport_diary_allowed_users()` (Task 3) with
  matching `array $groupids, array $userids` shape.
