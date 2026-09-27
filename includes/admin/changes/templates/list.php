<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Admin\Changes;

if (!defined('ABSPATH')) {
    exit();
}

if (!current_user_can_manage()) {
    wp_die(esc_html__('Not allowed.', domain: 'wppilot'), title: '', args: ['response' => 403]);
}

/** @var array<string, string|int> $filters */
$rows = \wppilot_query_change_log($filters);
$total = count($rows);
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination of a read.
$paged = max(1, (int) ($_GET['paged'] ?? 1));
$pages = max(1, (int) ceil($total / PER_PAGE));
$paged = min($paged, $pages);
$visible = array_slice($rows, ($paged - 1) * PER_PAGE, PER_PAGE);
$undoable = count(array_filter($rows, static fn(array $row): bool => \wppilot_change_status($row) === 'undoable'));

// How many rows each group has in the whole log, so a row can say "1 of a batch of 40".
$group_sizes = [];
foreach (\wppilot_get_change_log() as $row) {
    $group = (string) ($row['group'] ?? '');
    if ($group !== '') {
        $group_sizes[$group] = ($group_sizes[$group] ?? 0) + 1;
    }
}
$datetime_format = \wppilot_get_datetime_format();
$active_group = (string) ($filters['group'] ?? '');

?>
<div class="wrap wppilot-wrap wppilot-changes">
    <?php \wppilot_render_admin_header(esc_html__('What agents changed, and the way back', domain: 'wppilot')); ?>

    <div class="wppilot-panel">
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="wppilot-changes__filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(PAGE_SLUG); ?>">
            <?php if ($active_group !== '') { ?>
                <input type="hidden" name="group" value="<?php echo esc_attr($active_group); ?>">
            <?php } ?>
            <label>
                <span><?php esc_html_e('Ability', domain: 'wppilot'); ?></span>
                <input type="search" name="ability" placeholder="wppilot/update-" value="<?php echo esc_attr((string) ($filters['ability'] ?? '')); ?>">
            </label>
            <label>
                <span><?php esc_html_e('Agent', domain: 'wppilot'); ?></span>
                <input type="search" name="agent" list="wppilot-changes-agents" placeholder="<?php esc_attr_e('Connection or client name', domain: 'wppilot'); ?>" value="<?php echo esc_attr((string) ($filters['agent'] ?? '')); ?>">
                <?php // Every credential that has reached the MCP endpoint, access tokens included, so "what did the SEO token do" is a pick, not a guess at its name. ?>
                <datalist id="wppilot-changes-agents">
                    <?php foreach (agent_filter_options() as $credential => $label) { ?>
                        <option value="<?php echo esc_attr($credential); ?>"><?php echo esc_html($label); ?></option>
                    <?php } ?>
                </datalist>
            </label>
            <label>
                <span><?php esc_html_e('From', domain: 'wppilot'); ?></span>
                <input type="date" name="since" value="<?php echo esc_attr((string) ($filters['since'] ?? '')); ?>">
            </label>
            <label>
                <span><?php esc_html_e('To', domain: 'wppilot'); ?></span>
                <input type="date" name="until" value="<?php echo esc_attr((string) ($filters['until'] ?? '')); ?>">
            </label>
            <label>
                <span><?php esc_html_e('Status', domain: 'wppilot'); ?></span>
                <select name="status">
                    <option value=""><?php esc_html_e('Any', domain: 'wppilot'); ?></option>
                    <?php foreach (['undoable', 'rolled-back', 'not-reversible'] as $status) { ?>
                        <option value="<?php echo esc_attr($status); ?>" <?php selected((string) ($filters['status'] ?? ''), $status); ?>><?php echo esc_html(status_label($status)); ?></option>
                    <?php } ?>
                </select>
            </label>
            <label>
                <span><?php esc_html_e('Kind', domain: 'wppilot'); ?></span>
                <select name="kind">
                    <option value=""><?php esc_html_e('Changes and audited reads', domain: 'wppilot'); ?></option>
                    <option value="change" <?php selected((string) ($filters['kind'] ?? ''), 'change'); ?>><?php esc_html_e('Changes only', domain: 'wppilot'); ?></option>
                    <option value="audit-read" <?php selected((string) ($filters['kind'] ?? ''), 'audit-read'); ?>><?php esc_html_e('Audited reads only', domain: 'wppilot'); ?></option>
                </select>
            </label>
            <span class="wppilot-changes__filter-actions">
                <button type="submit" class="button"><?php esc_html_e('Filter', domain: 'wppilot'); ?></button>
                <?php if ($filters !== []) { ?>
                    <a class="button-link" href="<?php echo esc_url(list_url()); ?>"><?php esc_html_e('Clear', domain: 'wppilot'); ?></a>
                <?php } ?>
            </span>
        </form>

        <?php if ($active_group !== '') { ?>
            <p class="wppilot-changes__scope">
                <?php echo esc_html(sprintf(
                    /* translators: %d: number of changes in the batch */
                    _n('Showing one bulk call: %d change.', 'Showing one bulk call: %d changes.', $total, 'wppilot'),
                    $total,
                )); ?>
                <a href="<?php echo esc_url(list_url(array_diff_key($filters, ['group' => true]))); ?>"><?php esc_html_e('Show everything', domain: 'wppilot'); ?></a>
            </p>
        <?php } ?>

        <div class="wppilot-changes__toolbar">
            <span class="wppilot-muted">
                <?php echo esc_html(sprintf(
                    /* translators: 1: number of matching rows, 2: how many of them can be undone */
                    __('%1$d matching, %2$d can be undone.', domain: 'wppilot'),
                    $total,
                    $undoable,
                )); ?>
            </span>
            <?php if ($undoable > 0 && $filters !== []) { ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm(this.dataset.confirm);" data-confirm="<?php echo esc_attr(sprintf(
                    /* translators: %d: number of changes to undo */
                    _n('Undo %d change? Each is restored and verified; any that fail are listed afterwards.', 'Undo %d changes? Each is restored and verified; any that fail are listed afterwards.', $undoable, 'wppilot'),
                    $undoable,
                )); ?>">
                    <input type="hidden" name="action" value="wppilot_changes_undo_matching">
                    <?php wp_nonce_field('wppilot_changes_undo_matching'); ?>
                    <?php render_filter_fields(array_diff_key($filters, ['status' => true])); ?>
                    <button type="submit" class="button" <?php disabled($undoable > UNDO_MATCHING_MAX); ?>>
                        <?php echo esc_html(sprintf(
                            /* translators: %d: number of changes */
                            _n('Undo the %d change shown', 'Undo all %d changes shown', $undoable, 'wppilot'),
                            $undoable,
                        )); ?>
                    </button>
                </form>
            <?php } ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wppilot-changes__export">
                <input type="hidden" name="action" value="wppilot_changes_export">
                <?php wp_nonce_field('wppilot_changes_export'); ?>
                <?php render_filter_fields($filters); ?>
                <button type="submit" name="format" value="csv" class="button"><?php esc_html_e('Download CSV', domain: 'wppilot'); ?></button>
                <button type="submit" name="format" value="json" class="button"><?php esc_html_e('Download JSON', domain: 'wppilot'); ?></button>
            </form>
        </div>

        <?php if ($visible === []) { ?>
            <p class="wppilot-muted">
                <?php echo esc_html($filters === []
                    ? __('No changes yet. Every write an agent makes through WPPilot is recorded here, with a way to undo it when WPPilot kept a before-image.', domain: 'wppilot')
                    : __('Nothing matches those filters.', domain: 'wppilot')); ?>
            </p>
        <?php } else { ?>
            <table class="widefat striped wppilot-changes__table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('When', domain: 'wppilot'); ?></th>
                        <th scope="col"><?php esc_html_e('Ability', domain: 'wppilot'); ?></th>
                        <th scope="col"><?php esc_html_e('By', domain: 'wppilot'); ?></th>
                        <th scope="col"><?php esc_html_e('Status', domain: 'wppilot'); ?></th>
                        <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', domain: 'wppilot'); ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($visible as $entry) {
                        $id = (string) ($entry['id'] ?? '');
                        $status = \wppilot_change_status($entry);
                        $group = (string) ($entry['group'] ?? '');
                        $recorded = strtotime((string) ($entry['recorded_at'] ?? ''));
                        $is_audit = ($entry['kind'] ?? 'change') === 'audit-read';
                        ?>
                        <tr>
                            <td>
                                <?php echo esc_html($recorded !== false ? wp_date($datetime_format, $recorded) : ''); ?>
                            </td>
                            <td>
                                <code><?php echo esc_html((string) ($entry['ability'] ?? '')); ?></code>
                                <?php if ($is_audit) { ?>
                                    <span class="wppilot-badge"><?php esc_html_e('Audited read', domain: 'wppilot'); ?></span>
                                <?php } ?>
                                <?php if ($group !== '' && $active_group === '' && ($group_sizes[$group] ?? 0) > 1) { ?>
                                    <a class="wppilot-changes__batch" href="<?php echo esc_url(list_url(['group' => $group])); ?>">
                                        <?php echo esc_html(sprintf(
                                            /* translators: %d: number of changes in the batch */
                                            __('batch of %d', domain: 'wppilot'),
                                            $group_sizes[$group],
                                        )); ?>
                                    </a>
                                <?php } ?>
                            </td>
                            <td>
                                <?php $credential = (string) (is_array($entry['agent'] ?? null) ? ($entry['agent']['credential'] ?? '') : ''); ?>
                                <?php if ($credential !== '') { ?>
                                    <a href="<?php echo esc_url(list_url(['agent' => $credential])); ?>"><?php echo esc_html(actor_label($entry)); ?></a>
                                <?php } else { ?>
                                    <?php echo esc_html(actor_label($entry)); ?>
                                <?php } ?>
                            </td>
                            <td>
                                <span class="wppilot-changes__status wppilot-changes__status--<?php echo esc_attr($status); ?>">
                                    <?php echo esc_html($is_audit ? __('Read', domain: 'wppilot') : status_label($status)); ?>
                                </span>
                            </td>
                            <td class="wppilot-changes__actions">
                                <a class="button button-small" href="<?php echo esc_url(detail_url($id)); ?>"><?php esc_html_e('Details', domain: 'wppilot'); ?></a>
                                <?php if ($status === 'undoable') { ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm(this.dataset.confirm);" data-confirm="<?php esc_attr_e('Undo this change?', domain: 'wppilot'); ?>">
                                        <input type="hidden" name="action" value="wppilot_changes_undo">
                                        <input type="hidden" name="change_id" value="<?php echo esc_attr($id); ?>">
                                        <?php wp_nonce_field('wppilot_changes_undo'); ?>
                                        <button type="submit" class="button button-small"><?php esc_html_e('Undo', domain: 'wppilot'); ?></button>
                                    </form>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>

            <?php if ($pages > 1) { ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php echo wp_kses_post((string) paginate_links([
                        'base' => add_query_arg('paged', '%#%', list_url($filters)),
                        'format' => '',
                        'current' => $paged,
                        'total' => $pages,
                    ])); ?>
                </div></div>
            <?php } ?>
        <?php } ?>
    </div>
</div>
