<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\PromptLibrary;

/**
 * The prompt library: ready-made things to say to an agent.
 *
 * WPPilot exposes 70 abilities, and the hardest part of using it is not the
 * connection — it is knowing what to ask for. "Rewrite my homepage" gets a
 * shrug; a prompt that names the page, the tone, the constraints and the
 * approval step gets work done. This library is that difference, written down.
 *
 * Not to be confused with WPPilot\Skills\Prompts, which registers MCP
 * prompt-mode skills for the protocol. These are for humans: copy, paste into
 * your own client, edit to taste.
 *
 * Free ships the Gutenberg and site-care packs. Pro adds editor, theme,
 * industry, commerce, and SEO packs through the `wppilot_prompt_packs` filter,
 * so this file never learns Pro exists and keeps working unchanged when it is
 * absent.
 *
 * To add prompts: append to the array in gutenberg_pack() here, or hook the
 * filter from anywhere else. A pack is data — slug, label, group, and a list of
 * prompts — with no behaviour to keep in step.
 */

if (!defined('ABSPATH')) {
    exit();
}

const PAGE = 'wppilot-prompts';

/**
 * Pack groups, in display order.
 *
 * People enter the library from three directions: the editor/theme they use,
 * the industry they serve, or the job they need done. Industry prompts still
 * require the target builder as an explicit field, so choosing an industry can
 * never silently produce foreign editor data.
 *
 * @return array<string, string>
 */
function groups(): array
{
    return [
        'builder' => __('By editor', domain: 'wppilot'),
        'industry' => __('By industry', domain: 'wppilot'),
        'workflow' => __('By task', domain: 'wppilot'),
    ];
}

/**
 * Every prompt pack available on this site.
 *
 * @return list<array{slug: string, label: string, group: string, description: string, pro: bool, prompts: list<array{title: string, description: string, prompt: string}>}>
 */
function packs(): array
{
    $packs = [gutenberg_pack(), content_pack()];

    /**
     * Filter the prompt library.
     *
     * Add a pack: slug, label, group (see groups()), description, pro flag, and
     * a list of prompts with title, description and prompt text.
     *
     * @param list<array<string, mixed>> $packs
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('wppilot_prompt_packs', $packs);
    if (!is_array($filtered)) {
        return $packs;
    }

    $safe = [];
    /** @var mixed $pack */
    foreach ($filtered as $pack) {
        $clean = normalize_pack($pack);
        if ($clean !== null) {
            $safe[] = $clean;
        }
    }

    return $safe;
}

/**
 * Coerce a filtered pack into the documented shape, or reject it.
 *
 * Anything can hook the filter, so nothing downstream should have to guess
 * whether a key is present or a prompt is a string. A malformed pack is dropped
 * rather than half-rendered.
 *
 * @param mixed $pack
 * @return array{slug: string, label: string, group: string, description: string, pro: bool, prompts: list<array{title: string, description: string, prompt: string}>}|null
 */
function normalize_pack(mixed $pack): ?array
{
    if (!is_array($pack)) {
        return null;
    }

    $slug = is_string($pack['slug'] ?? null) ? $pack['slug'] : '';
    $label = is_string($pack['label'] ?? null) ? $pack['label'] : '';
    if ($slug === '' || $label === '') {
        return null;
    }

    $group = is_string($pack['group'] ?? null) ? $pack['group'] : 'workflow';
    if (!array_key_exists($group, groups())) {
        $group = 'workflow';
    }

    $prompts = normalize_prompts($pack['prompts'] ?? null);
    if ($prompts === []) {
        return null;
    }

    return [
        'slug' => $slug,
        'label' => $label,
        'group' => $group,
        'description' => is_string($pack['description'] ?? null) ? $pack['description'] : '',
        'pro' => ($pack['pro'] ?? false) === true,
        'prompts' => $prompts,
    ];
}

/**
 * Coerce a pack's prompt list, dropping anything unusable.
 *
 * A prompt with no text is not a prompt, and one with no title cannot be shown
 * or copied, so both are dropped rather than rendered as an empty card.
 *
 * @param mixed $raw_prompts
 * @return list<array{title: string, description: string, prompt: string}>
 */
function normalize_prompts(mixed $raw_prompts): array
{
    $prompts = [];

    /** @var mixed $raw */
    foreach (is_array($raw_prompts) ? $raw_prompts : [] as $raw) {
        if (!is_array($raw)) {
            continue;
        }

        $text = is_string($raw['prompt'] ?? null) ? trim($raw['prompt']) : '';
        $title = is_string($raw['title'] ?? null) ? $raw['title'] : '';
        if ($text === '' || $title === '') {
            continue;
        }

        $prompts[] = [
            'title' => $title,
            'description' => is_string($raw['description'] ?? null) ? $raw['description'] : '',
            'prompt' => $text,
        ];
    }

    return $prompts;
}

/**
 * The whole-site brief, for whichever editor the caller names.
 *
 * The hardest prompt to write is the first one on an empty site: "build me an
 * accounting firm's website" is too vague to act on, and the result is a
 * five-page site of adjectives. This spells out the pages, the sections in
 * each, the tone, and the approval points — which is the difference between a
 * demo and something a client could look at.
 *
 * One prompt covers every kind of business rather than one prompt per industry.
 * The site type is a variable the person fills in, and the per-industry page
 * plans travel with it, so adding "law firm" later is one line here instead of
 * a new pack in every builder.
 *
 * $builder_line is the editor-specific instruction — the single sentence that
 * decides whether the output is editable in the tool the customer actually
 * uses, or a pile of blocks they cannot touch.
 */
function full_site_brief(string $builder_line): string
{
    $intro = __(
        'Build a complete website for [BUSINESS NAME], a [SITE TYPE] in [CITY/REGION] serving [WHO THEY SERVE].',
        domain: 'wppilot',
    );

    $plans = __(
        'Page plan — use the one matching the site type, and adapt it:

ACCOUNTING / PROFESSIONAL SERVICES
Home · Services (bookkeeping, tax, payroll, advisory) · About + team credentials · Pricing (three tiers, "from" prices) · Contact · Privacy Policy
Tone: precise, calm, trustworthy. No hype. Never invent tax figures, rates, deadlines or regulatory claims — write [CONFIRM: …] instead.

RESTAURANT / HOSPITALITY
Home (hours prominent) · Menu (sections, prices, dietary markers) · About + chef · Book a table · Contact (hours per day, parking, accessibility)
Tone: warm but concrete — describe food by ingredient and preparation, not adjectives. Hours, menu and booking must be one click from anywhere.

AGENCY / STUDIO
Home · Services (what is included, process, timeline) · Work (three case studies: problem, approach, result) · About · Contact with budget and timeline fields
Tone: confident, specific, outcome-led. Write [CONFIRM: metric] rather than inventing results.

CLINIC / HEALTH PRACTICE
Home · Services (what each treatment involves, duration, aftercare) · Team with qualifications · New patients (what to bring, fees) · Contact + emergency info
Tone: reassuring and plain; assume the reader is anxious. Make no claims about outcomes and never imply a treatment cures anything. Registration numbers and regulatory text stay as placeholders.

PORTFOLIO / INDIVIDUAL
Home · Work (four projects: brief, approach, outcome) · About · Contact
Tone: first person, direct, short sentences. Keep it small — the job is to show work.

E-COMMERCE
Home · Shop landing · About · Shipping & returns · FAQ · Contact
Tone: plain and specific. Do not invent shipping times, prices or return windows — use [CONFIRM: …].',
        domain: 'wppilot',
    );

    $rules = __('Rules for the whole build:
- Create everything as drafts. Publish nothing without asking me.
- Real, specific copy for this business — never lorem ipsum.
- Use the site\'s existing theme, colours and fonts.
- Add each page to the main navigation in the order listed.
- Anything you are not certain of goes in as [CONFIRM: …] rather than a plausible guess.
- When you are done, list every page you created with its edit link.', domain: 'wppilot');

    return $intro . "\n\n" . $builder_line . "\n\n" . $plans . "\n\n" . $rules;
}

/**
 * The free pack: the block editor, which every WordPress site has.
 *
 * @return array{slug: string, label: string, group: string, description: string, pro: bool, prompts: list<array{title: string, description: string, prompt: string}>}
 */
function gutenberg_pack(): array
{
    return [
        'slug' => 'gutenberg',
        'label' => __('Gutenberg', domain: 'wppilot'),
        'group' => 'builder',
        'description' => __(
            'Block editor work. These use core blocks only, so they run on any theme.',
            domain: 'wppilot',
        ),
        'pro' => false,
        'prompts' => [
            [
                'title' => __('Build a complete website', domain: 'wppilot'),
                'description' => __(
                    'Multi-page brief. Name the kind of business; page plans for six common types are included.',
                    domain: 'wppilot',
                ),
                'prompt' => full_site_brief(__(
                    'Build it with core Gutenberg blocks only, so it works on any theme and stays editable in the block editor.',
                    domain: 'wppilot',
                )),
            ],
            [
                'title' => __('Build a landing page', domain: 'wppilot'),
                'description' => __('A complete page from a one-line brief.', domain: 'wppilot'),
                'prompt' => __(
                    "Create a new draft page titled \"[PAGE TITLE]\" using core Gutenberg blocks only.\n\nStructure:\n- Hero: heading, one-sentence subheading, primary call-to-action button\n- Three feature columns, each with a heading and two sentences\n- A short social-proof section\n- Closing call-to-action\n\nRules:\n- Leave it as a draft. Do not publish.\n- Use real, specific copy about [WHAT THE PAGE IS FOR] — no lorem ipsum.\n- Use only core blocks so it works on any theme.\n- When you are done, give me the edit link.",
                    domain: 'wppilot',
                ),
            ],
            [
                'title' => __('Rewrite a page for clarity', domain: 'wppilot'),
                'description' => __('Improves the words, leaves the layout alone.', domain: 'wppilot'),
                'prompt' => __(
                    "Read the page \"[PAGE TITLE]\" on this site and rewrite its text for clarity.\n\nKeep:\n- The existing block structure and layout exactly as it is\n- All links, images and buttons\n\nChange:\n- Shorten sentences, cut filler, use plain language\n- Keep the same meaning and any factual claims\n- Match the tone of the rest of the site\n\nShow me a before/after of each block you would change, and wait for my approval before saving anything.",
                    domain: 'wppilot',
                ),
            ],
            [
                'title' => __('Audit content quality', domain: 'wppilot'),
                'description' => __('Read-only review across published posts.', domain: 'wppilot'),
                'prompt' => __(
                    "Review the 20 most recent published posts on this site and report on:\n\n- Posts with no excerpt\n- Posts with no featured image\n- Titles longer than 60 characters\n- Posts that have not been updated in over a year\n- Any obviously broken or placeholder content\n\nDo not change anything. Give me a table sorted by how much each post needs attention.",
                    domain: 'wppilot',
                ),
            ],
            [
                'title' => __('Turn a draft into a publishable post', domain: 'wppilot'),
                'description' => __('Structure, headings, excerpt, and internal links.', domain: 'wppilot'),
                'prompt' => __(
                    "Take my draft post \"[DRAFT TITLE]\" and make it publishable:\n\n- Add clear H2 and H3 headings so it can be skimmed\n- Write a 25-word excerpt\n- Suggest a focus keyword and check the title supports it\n- Add two or three internal links to relevant existing posts on this site\n- Flag anything that reads as unfinished\n\nKeep it a draft. List every change you made.",
                    domain: 'wppilot',
                ),
            ],
            [
                'title' => __('Create a reusable block pattern', domain: 'wppilot'),
                'description' => __('Once, then reuse everywhere.', domain: 'wppilot'),
                'prompt' => __(
                    "Create a reusable Gutenberg pattern called \"[PATTERN NAME]\" for [WHAT IT IS FOR, e.g. a testimonial card].\n\n- Core blocks only\n- Sensible placeholder text that shows how it should be filled in\n- Works at mobile and desktop widths\n\nAfter creating it, tell me how to insert it in the editor.",
                    domain: 'wppilot',
                ),
            ],
        ],
    ];
}

/**
 * Free, editor-agnostic: the jobs people ask for regardless of how the site is
 * built.
 *
 * @return array{slug: string, label: string, group: string, description: string, pro: bool, prompts: list<array{title: string, description: string, prompt: string}>}
 */
function content_pack(): array
{
    return [
        'slug' => 'site-care',
        'label' => __('Site care', domain: 'wppilot'),
        'group' => 'workflow',
        'description' => __('Housekeeping an agent can do faster than you can.', domain: 'wppilot'),
        'pro' => false,
        'prompts' => [
            [
                'title' => __('Weekly site report', domain: 'wppilot'),
                'description' => __('What changed, what needs attention.', domain: 'wppilot'),
                'prompt' => __(
                    "Give me a short report on this WordPress site:\n\n- Content published or updated in the last 7 days\n- Drafts that have sat untouched for more than 30 days\n- Pending comments\n- Plugins and themes with updates available\n- Anything that looks broken or misconfigured\n\nRead only — change nothing. Keep it under 300 words.",
                    domain: 'wppilot',
                ),
            ],
            [
                'title' => __('Find and fix broken links', domain: 'wppilot'),
                'description' => __('Reports first, fixes only what you approve.', domain: 'wppilot'),
                'prompt' => __(
                    "Check the published pages and posts on this site for links that point to:\n\n- Pages that no longer exist on this site\n- Old URLs that now redirect\n- http:// where https:// is available\n\nShow me the list first, grouped by page, with the suggested replacement for each. Fix only the ones I confirm.",
                    domain: 'wppilot',
                ),
            ],
            [
                'title' => __('Clean up the media library', domain: 'wppilot'),
                'description' => __('Find unused and oversized uploads.', domain: 'wppilot'),
                'prompt' => __(
                    "Audit the media library:\n\n- Images not attached to any post or page\n- Files over 1 MB that could be resized\n- Images with no alt text that are used on published pages\n\nDo not delete anything. Give me the list with file sizes, and for the missing alt text suggest wording based on where each image is used.",
                    domain: 'wppilot',
                ),
            ],
            [
                'title' => __('Prepare a site for handover', domain: 'wppilot'),
                'description' => __('The checklist before you give a client the keys.', domain: 'wppilot'),
                'prompt' => __(
                    "I am handing this site over to a client. Check and report on:\n\n- Placeholder content still live (lorem ipsum, \"Sample Page\", default tagline)\n- Pages with no SEO title or description\n- Missing favicon or site icon\n- Default \"Hello world\" post or default comment still present\n- Admin accounts that should be removed\n- Whether search engine visibility is switched on\n\nReport only. I will decide what to change.",
                    domain: 'wppilot',
                ),
            ],
        ],
    ];
}
