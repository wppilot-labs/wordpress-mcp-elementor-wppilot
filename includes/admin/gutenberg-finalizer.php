<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

namespace WPPilot\GutenbergFinalizer;

if (!defined('ABSPATH')) {
    exit();
}

function boot_gutenberg_finalizer_admin(): void
{
    add_action('admin_menu', __NAMESPACE__ . '\\register_gutenberg_finalizer_menu', priority: 60);
    add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_gutenberg_finalizer_assets');
}

function gutenberg_finalizer_page_slug(): string
{
    return 'wppilot-gutenberg-finalize';
}

/**
 * The screen's own title.
 *
 * Carried here rather than in the nav map because this page is not on the rail:
 * wppilot_nav_label() falls back to the raw slug for anything the map does not
 * list, and "wppilot-gutenberg-finalize" is not a heading. The lookup still runs
 * first so a site that has renamed the screen keeps its name.
 */
function gutenberg_finalizer_title(): string
{
    return \wppilot_nav_label(gutenberg_finalizer_page_slug(), __('Block Queue', domain: 'wppilot'));
}

/**
 * Register the finalizer page, without putting it in the menu.
 *
 * An empty parent slug registers a page that resolves by URL but appears in no
 * menu, which is what this one should always have been. It is not a screen
 * anybody visits on purpose: it loads a hidden block editor in an iframe so
 * WordPress' own serializer can turn agent-supplied blocks into post content,
 * and its own copy tells the reader to ignore it and leave the tab open.
 *
 * Listed under Studio as "Block Queue" it read as a feature, so people opened it
 * expecting a queue they could work, and found a page asking them to go away.
 * The URL still works — the finalizer runtime opens it itself, and the
 * troubleshooting docs link to it — it simply stops advertising.
 */
function register_gutenberg_finalizer_menu(): void
{
    if (!defined('WPPILOT_VERSION')) {
        return;
    }

    add_submenu_page(
        parent_slug: '',
        page_title: gutenberg_finalizer_title(),
        menu_title: '',
        capability: 'edit_posts',
        menu_slug: gutenberg_finalizer_page_slug(),
        callback: __NAMESPACE__ . '\\render_gutenberg_finalizer_page',
    );
}

function enqueue_gutenberg_finalizer_assets(string $hook_suffix): void
{
    if (!is_gutenberg_finalizer_request()) {
        return;
    }

    enqueue_gutenberg_finalizer_runtime_assets();

    unset($hook_suffix);
}

function enqueue_gutenberg_finalizer_runtime_assets(): void
{
    // phpcs:disable WordPress.WP.EnqueuedResourceParameters.NotInFooter -- `args: true` IS the in_footer flag; the sniff cannot read PHP named arguments.
    wp_register_script(
        handle: 'wppilot-gutenberg-finalizer',
        src: false,
        deps: ['wp-api-fetch', 'wp-blocks', 'wp-block-library', 'wp-format-library'],
        ver: WPPILOT_VERSION,
        args: true,
    );
    // phpcs:enable WordPress.WP.EnqueuedResourceParameters.NotInFooter

    $config = [
        'nonce' => wp_create_nonce('wp_rest'),
    ];
    $encoded_config = wp_json_encode($config);
    if (is_string($encoded_config)) {
        wp_add_inline_script(
            handle: 'wppilot-gutenberg-finalizer',
            data: 'window.wppilotGutenbergFinalizer = ' . $encoded_config . ';',
            position: 'before',
        );
    }
    wp_add_inline_script(handle: 'wppilot-gutenberg-finalizer', data: gutenberg_finalizer_script());
    wp_enqueue_script(handle: 'wppilot-gutenberg-finalizer');
}

function is_gutenberg_finalizer_request(): bool
{
    return ($_GET['page'] ?? '') === gutenberg_finalizer_page_slug();
}

function render_gutenberg_finalizer_page(): void
{
    if (!current_user_can('edit_posts')) {
        return;
    }

    if (function_exists('wppilot_render_admin_header')) {
        wppilot_render_admin_header();
    }

    ?>
    <div class="wrap wppilot-gb-finalizer" id="wppilot-gb-finalizer">
        <h1 class="wp-heading-inline"><?php echo esc_html(gutenberg_finalizer_title()); ?></h1>
        <hr class="wp-header-end">
        <?php render_gutenberg_finalizer_styles(); ?>

        <?php render_gutenberg_finalizer_page_content(); ?>
    </div>
    <?php
}

function render_gutenberg_finalizer_page_content(): void
{
    render_gutenberg_finalizer_dashboard();
}

function render_gutenberg_finalizer_dashboard(): void
{ ?>
    <div id="wppilot-gb-notice" class="notice" hidden><p></p></div>

    <section class="summary-panel" aria-live="polite">
        <p><?php esc_html_e(
            'This background utility page is used by WPPilot to safely validate and serialize Gutenberg blocks. During Gutenberg editing sessions, this page serves as a technical bridge, utilizing the native WordPress editor engine to serialize block structures securely.',
            domain: 'wppilot',
        ); ?></p>
        <p><strong><?php esc_html_e(
            'Please keep this tab open in the background while an active session is running. You can safely ignore this page, but closing it before the session completes will pause the updates.',
            domain: 'wppilot',
        ); ?></strong></p>
        <p id="wppilot-gb-progress" class="progress-line"><?php esc_html_e(
            'Checking for queued Gutenberg changes...',
            domain: 'wppilot',
        ); ?></p>
    </section>
    <div class="wppilot-gb-editor-frame-wrap" aria-hidden="true">
        <iframe
            id="wppilot-gb-editor-frame"
            class="wppilot-gb-editor-frame"
            title="<?php esc_attr_e('WPPilot hidden block editor', domain: 'wppilot'); ?>"
            tabindex="-1"
            src="about:blank"
        ></iframe>
    </div>
    <?php }

function render_gutenberg_finalizer_styles(): void
{ ?>
    <?php // Styles for this screen live in includes/assets/admin.css. ?>
    <?php }

function gutenberg_finalizer_script(): string
{
    return <<<'JS'
        ( function () {
            const config = window.wppilotGutenbergFinalizer || {};
            const rootId = config.rootId || 'wppilot-gb-finalizer';
            const root = document.getElementById( rootId );
            if ( ! root || ! window.wp || ! wp.apiFetch ) {
                return;
            }

            const apiFetch = wp.apiFetch;
            apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );

            const progress = document.getElementById( 'wppilot-gb-progress' );
            const notice = document.getElementById( 'wppilot-gb-notice' );
            let editorFrame = document.getElementById( 'wppilot-gb-editor-frame' );
            const editorLoadTimeoutMs = Number( config.editorLoadTimeoutMs || 30000 );
            const blockRegistrationTimeoutMs = Number( config.blockRegistrationTimeoutMs || 30000 );
            let leaseOwner = '';
            let isRunning = false;
            let dashboardPollRunning = false;
            let editorFrameUrl = '';
            let editorFrameLoadPromise = Promise.resolve();
            let frameAccessError = null;
            let fallbackWarning = '';

            const path = ( suffix ) => `/wppilot/v1${ suffix }`;

            const setNotice = ( type, message ) => {
                if ( ! notice ) {
                    return;
                }
                notice.className = `notice notice-${ type }`;
                notice.hidden = false;
                const p = notice.querySelector( 'p' );
                if ( p ) {
                    p.textContent = message;
                }
            };

            const clearNotice = () => {
                if ( notice ) {
                    notice.hidden = true;
                }
            };

            const setProgress = ( message ) => {
                if ( progress ) {
                    progress.textContent = message;
                }
            };

            const issueMessage = ( issue ) => {
                if ( ! issue ) {
                    return 'Block validation failed.';
                }
                if ( typeof issue === 'string' ) {
                    return issue;
                }
                if ( issue.message ) {
                    return issue.message;
                }
                if ( Array.isArray( issue.args ) ) {
                    return issue.args.map( String ).join( ' ' );
                }
                try {
                    return JSON.stringify( issue );
                } catch ( error ) {
                    return 'Block validation failed.';
                }
            };

            const compactIssue = ( validation, issue ) => ( {
                block_name: validation.name || '',
                path: validation.path || '',
                category: 'validation',
                code: 'block_validation_failed',
                message: issueMessage( issue ).replace( /\s+/g, ' ' ).trim().slice( 0, 300 ),
            } );

            const sleep = ( milliseconds ) => new Promise( ( resolve ) => {
                window.setTimeout( resolve, milliseconds );
            } );

            const sameOriginEditorUrl = ( editorUrl ) => {
                if ( ! editorUrl ) {
                    throw new Error( 'The queued Gutenberg item did not include an editor URL.' );
                }

                const url = new URL( editorUrl, window.location.href );
                if ( url.origin !== window.location.origin ) {
                    throw new Error( 'The editor iframe URL is not same-origin.' );
                }

                return url.href;
            };

            const ensureEditorFrame = () => {
                if ( editorFrame ) {
                    return editorFrame;
                }

                const wrap = document.createElement( 'div' );
                wrap.className = 'wppilot-gb-editor-frame-wrap';
                wrap.setAttribute( 'aria-hidden', 'true' );
                wrap.style.position = 'absolute';
                wrap.style.top = '0';
                wrap.style.left = '-10000px';
                wrap.style.width = '1280px';
                wrap.style.height = '900px';
                wrap.style.overflow = 'hidden';
                wrap.style.opacity = '0';
                wrap.style.pointerEvents = 'none';

                const frame = document.createElement( 'iframe' );
                frame.id = 'wppilot-gb-editor-frame';
                frame.className = 'wppilot-gb-editor-frame';
                frame.title = 'WPPilot hidden block editor';
                frame.tabIndex = -1;
                frame.src = 'about:blank';
                frame.style.display = 'block';
                frame.style.width = '1280px';
                frame.style.height = '900px';
                frame.style.border = '0';

                wrap.appendChild( frame );
                root.appendChild( wrap );
                editorFrame = frame;

                return frame;
            };

            const navigateEditorFrame = ( editorUrl ) => {
                const frame = ensureEditorFrame();

                const nextUrl = sameOriginEditorUrl( editorUrl );
                if ( editorFrameUrl === nextUrl ) {
                    return editorFrameLoadPromise;
                }

                editorFrameUrl = nextUrl;
                editorFrameLoadPromise = new Promise( ( resolve, reject ) => {
                    let settled = false;
                    const cleanup = () => {
                        frame.removeEventListener( 'load', onLoad );
                        window.clearTimeout( timeoutId );
                    };
                    const onLoad = () => {
                        if ( settled ) {
                            return;
                        }
                        settled = true;
                        cleanup();
                        resolve();
                    };
                    const timeoutId = window.setTimeout( () => {
                        if ( settled ) {
                            return;
                        }
                        settled = true;
                        cleanup();
                        reject( new Error( 'The hidden editor iframe did not finish loading.' ) );
                    }, editorLoadTimeoutMs );

                    frame.addEventListener( 'load', onLoad );
                    frame.src = nextUrl;
                } );
                editorFrameLoadPromise.catch( () => {
                    if ( editorFrameUrl === nextUrl ) {
                        editorFrameUrl = '';
                    }
                } );

                return editorFrameLoadPromise;
            };

            const iframeWindow = () => {
                if ( ! editorFrame || ! editorFrame.contentWindow ) {
                    return null;
                }

                try {
                    return editorFrame.contentWindow;
                } catch ( error ) {
                    return null;
                }
            };

            const requiredBlocksMethods = [ 'createBlock', 'serialize', 'parse', 'validateBlock', 'getBlockType' ];

            const usableBlocksApi = ( blocksApi ) => {
                if ( ! blocksApi ) {
                    return null;
                }

                const hasRequiredMethods = requiredBlocksMethods.every(
                    ( method ) => typeof blocksApi[ method ] === 'function'
                );

                return hasRequiredMethods ? blocksApi : null;
            };

            // Every read below crosses a frame boundary, so any property access can throw a
            // SecurityError when the frame ends up in another origin. Treat that as "not ready"
            // and record it, so the caller can stop polling and explain what happened.
            const editorBlocksApi = () => {
                try {
                    const frameWindow = iframeWindow();
                    if ( ! frameWindow ) {
                        return null;
                    }

                    const wpApi = frameWindow.wp;

                    return wpApi ? usableBlocksApi( wpApi.blocks ) : null;
                } catch ( error ) {
                    frameAccessError = error;
                    return null;
                }
            };

            const crossOriginFrameError = ( error ) => {
                const detail = error && error.message ? ` (${ error.message })` : '';
                const frameError = new Error(
                    'The hidden editor iframe loaded in a different origin, so its block editor runtime cannot be read. '
                    + 'This usually means the editor URL redirected to another host or scheme, or the iframe was blocked '
                    + `by an X-Frame-Options / Content-Security-Policy header, a proxy, or a browser extension.${ detail }`
                );
                frameError.code = 'editor_frame_cross_origin';
                return frameError;
            };

            const waitForEditorBlocksApi = async () => {
                const startedAt = Date.now();
                while ( Date.now() - startedAt < editorLoadTimeoutMs ) {
                    frameAccessError = null;
                    const blocksApi = editorBlocksApi();
                    if ( blocksApi ) {
                        return blocksApi;
                    }
                    // The frame already fired "load", so a blocked read will not resolve by waiting.
                    if ( frameAccessError ) {
                        throw crossOriginFrameError( frameAccessError );
                    }
                    await sleep( 100 );
                }

                throw new Error( 'The WordPress block editor JavaScript runtime is not available in the hidden iframe.' );
            };

            const collectBlockRefs = ( blocks, prefix = '' ) => {
                const refs = [];
                ( Array.isArray( blocks ) ? blocks : [] ).forEach( ( block, index ) => {
                    if ( ! block || typeof block !== 'object' ) {
                        return;
                    }

                    const pathText = prefix === '' ? String( index ) : `${ prefix }.${ index }`;
                    if ( typeof block.name === 'string' && block.name !== '' ) {
                        refs.push( { name: block.name, path: pathText } );
                    }
                    refs.push( ...collectBlockRefs( block.innerBlocks || [], pathText ) );
                } );
                return refs;
            };

            const uniqueBlockNames = ( refs ) => Array.from( new Set( refs.map( ( ref ) => ref.name ) ) );

            const missingRegistrationError = ( missingRefs ) => {
                const names = uniqueBlockNames( missingRefs );
                const error = new Error( `The editor iframe did not register required block types: ${ names.join( ', ' ) }.` );
                error.code = 'missing_block_registration';
                error.missingBlockRefs = missingRefs;
                return error;
            };

            let coreBlocksRegistered = false;

            // Fallback runtime: this admin page loads wp-blocks and wp-block-library itself, so core
            // blocks can be serialized here when the hidden iframe is unreachable.
            const localBlocksApi = () => {
                const wpApi = window.wp;
                if ( ! wpApi ) {
                    return null;
                }

                if ( ! coreBlocksRegistered ) {
                    coreBlocksRegistered = true;
                    if ( wpApi.blockLibrary && typeof wpApi.blockLibrary.registerCoreBlocks === 'function' ) {
                        wpApi.blockLibrary.registerCoreBlocks();
                    }
                }

                return usableBlocksApi( wpApi.blocks );
            };

            const waitForBlockRegistrations = async ( blocksApi, refs ) => {
                const startedAt = Date.now();
                let missingRefs = refs.filter( ( ref ) => ! blocksApi.getBlockType( ref.name ) );
                while ( missingRefs.length && Date.now() - startedAt < blockRegistrationTimeoutMs ) {
                    await sleep( 100 );
                    missingRefs = refs.filter( ( ref ) => ! blocksApi.getBlockType( ref.name ) );
                }

                if ( missingRefs.length ) {
                    throw missingRegistrationError( missingRefs );
                }
            };

            const loadEditorBlocksApi = async ( editorUrl, blocks ) => {
                const refs = collectBlockRefs( blocks );
                let frameError = null;

                try {
                    await navigateEditorFrame( editorUrl );
                    const blocksApi = await waitForEditorBlocksApi();
                    await waitForBlockRegistrations( blocksApi, refs );
                    return blocksApi;
                } catch ( error ) {
                    frameError = error;
                }

                const fallbackApi = localBlocksApi();
                if ( ! fallbackApi ) {
                    throw frameError;
                }

                const missingRefs = refs.filter( ( ref ) => ! fallbackApi.getBlockType( ref.name ) );
                if ( missingRefs.length ) {
                    // Blocks the iframe would have registered, such as plugin or theme blocks, are
                    // unavailable here. Report the missing names rather than the frame failure.
                    throw frameError && frameError.code === 'missing_block_registration'
                        ? frameError
                        : missingRegistrationError( missingRefs );
                }

                fallbackWarning = 'The hidden block editor iframe could not be used, so blocks were serialized with the '
                    + 'block runtime of this page. Only blocks registered on this page are supported. Reason: '
                    + ( frameError && frameError.message ? frameError.message : 'unknown.' );

                return fallbackApi;
            };

            const toBlock = ( blocksApi, spec ) => blocksApi.createBlock(
                spec.name,
                spec.attributes || {},
                ( spec.innerBlocks || [] ).map( ( innerSpec ) => toBlock( blocksApi, innerSpec ) )
            );

            const blockName = ( block ) => block.name || block.blockName || '';

            const validateBlocks = ( blocksApi, blocks, prefix = '' ) => {
                const validations = [];
                blocks.forEach( ( block, index ) => {
                    const pathText = prefix === '' ? String( index ) : `${ prefix }.${ index }`;
                    let result;
                    try {
                        result = blocksApi.validateBlock( block );
                    } catch ( error ) {
                        result = [ false, [ { message: error.message || String( error ) } ] ];
                    }
                    const isValid = Array.isArray( result ) ? result[ 0 ] === true : result === true;
                    const issues = Array.isArray( result ) ? ( result[ 1 ] || [] ) : [];
                    validations.push( {
                        name: blockName( block ),
                        path: pathText,
                        isValid,
                        issues,
                    } );
                    if ( Array.isArray( block.innerBlocks ) && block.innerBlocks.length ) {
                        validations.push( ...validateBlocks( blocksApi, block.innerBlocks, pathText ) );
                    }
                } );
                return validations;
            };

            const serializeJob = async ( job ) => {
                const blocks = job.blocks || [];
                const blocksApi = await loadEditorBlocksApi( job.editor_url || '', blocks );
                const created = blocks.map( ( spec ) => toBlock( blocksApi, spec ) );
                const content = blocksApi.serialize( created );
                const parsed = blocksApi.parse( content );
                const validations = validateBlocks( blocksApi, parsed );
                const errors = [];
                validations.forEach( ( validation ) => {
                    if ( validation.isValid ) {
                        return;
                    }
                    const issues = validation.issues.length ? validation.issues : [ { message: 'Block validation failed.' } ];
                    issues.forEach( ( issue ) => errors.push( compactIssue( validation, issue ) ) );
                } );
                return { content, validations, errors };
            };

            const failCurrentItem = async ( itemId, errors, message ) => apiFetch( {
                path: path( `/gutenberg/items/${ itemId }/fail` ),
                method: 'POST',
                data: {
                    lease_owner: leaseOwner,
                    errors,
                    message,
                },
            } );

            const heartbeat = async () => apiFetch( {
                path: path( '/gutenberg/finalizer-runtime/heartbeat' ),
                method: 'POST',
            } );

            const finalNotice = ( batch ) => {
                if ( batch && batch.status === 'finalized' ) {
                    if ( fallbackWarning ) {
                        setNotice( 'warning', fallbackWarning );
                    } else {
                        clearNotice();
                    }
                    setProgress( 'Nothing to do. The queue is ready.' );
                    return;
                }

                setProgress( 'Something needs attention. Return to the agent.' );
                setNotice( 'error', 'Something needs attention. Return to the agent.' );
            };

            const processBatch = async ( batchId, initialClaim = null ) => {
                const activeBatchId = Number( batchId || 0 );
                if ( ! activeBatchId ) {
                    return false;
                }
                if ( isRunning ) {
                    return false;
                }

                isRunning = true;
                fallbackWarning = '';
                try {
                    clearNotice();
                    setProgress( 'Working on queued Gutenberg changes...' );
                    const claim = initialClaim || await apiFetch( {
                        path: path( `/gutenberg/batches/${ activeBatchId }/claim` ),
                        method: 'POST',
                    } );
                    leaseOwner = claim.lease_owner;

                    let processed = 0;
                    const total = claim.batch && claim.batch.item_count ? claim.batch.item_count : 0;
                    while ( true ) {
                        const next = await apiFetch( {
                            path: path( `/gutenberg/batches/${ activeBatchId }/items/claim-next` ),
                            method: 'POST',
                            data: { lease_owner: leaseOwner },
                        } );
                        if ( next.done ) {
                            finalNotice( next.batch );
                            break;
                        }

                        const item = next.item;
                        setProgress(
                            total > 1
                                ? `Working on queued Gutenberg changes (${ processed + 1 } of ${ total })...`
                                : 'Working on queued Gutenberg changes...'
                        );
                        const job = await apiFetch( {
                            path: path( `/gutenberg/items/${ item.item_id }/spec?lease_owner=${ encodeURIComponent( leaseOwner ) }` ),
                            method: 'GET',
                        } );

                        try {
                            const result = await serializeJob( job );
                            if ( result.errors.length ) {
                                await failCurrentItem( item.item_id, result.errors, 'JS validation failed; canonical content was not written.' );
                                setProgress( 'Something needs attention. Return to the agent.' );
                                setNotice( 'error', 'Something needs attention. Return to the agent.' );
                                break;
                            }

                            const completed = await apiFetch( {
                                path: path( `/gutenberg/items/${ item.item_id }/complete` ),
                                method: 'POST',
                                data: {
                                    lease_owner: leaseOwner,
                                    content: result.content,
                                    validations: result.validations,
                                },
                            } );
                            processed += 1;
                            if ( completed.done ) {
                                finalNotice( completed.batch );
                                break;
                            }
                        } catch ( error ) {
                            const isMissingRegistration = error && error.code === 'missing_block_registration';
                            const errorItems = isMissingRegistration && Array.isArray( error.missingBlockRefs )
                                ? error.missingBlockRefs.map( ( ref ) => ( {
                                    block_name: ref.name || '',
                                    path: ref.path || '',
                                    category: 'registration',
                                    code: 'missing_block_registration',
                                    message: `Block "${ ref.name || '(missing name)' }" was not registered in the block editor runtime.`,
                                } ) )
                                : [ {
                                    block_name: '',
                                    path: '',
                                    category: 'serialization',
                                    code: ( error && error.code ) || 'js_exception',
                                    message: error && error.message ? error.message : String( error ),
                                } ];
                            await failCurrentItem(
                                item.item_id,
                                errorItems,
                                isMissingRegistration
                                    ? 'One or more Gutenberg blocks were not registered in the block editor runtime; canonical content was not written.'
                                    : 'The browser block serializer threw an exception.'
                            );
                            setProgress( 'Something needs attention. Return to the agent.' );
                            setNotice( 'error', 'Something needs attention. Return to the agent.' );
                            break;
                        }
                    }
                } catch ( error ) {
                    setNotice( 'error', 'The queue stopped. Return to the agent.' );
                    setProgress( 'Something needs attention. Return to the agent.' );
                    return false;
                } finally {
                    isRunning = false;
                }

                return true;
            };

            const processDashboardQueue = async () => {
                if ( dashboardPollRunning || isRunning ) {
                    return;
                }

                dashboardPollRunning = true;
                try {
                    await heartbeat();
                    const next = await apiFetch( {
                        path: path( '/gutenberg/batches/claim-next' ),
                        method: 'POST',
                    } );
                    if ( next.done || ! next.claim || ! next.claim.batch ) {
                        clearNotice();
                        setProgress( 'Nothing to do. The queue is ready.' );
                        return;
                    }

                    clearNotice();
                    setProgress( 'Working on queued Gutenberg changes...' );
                    await processBatch( next.claim.batch.batch_id, next.claim );
                } catch ( error ) {
                    setNotice( 'error', 'Queue disconnected. Reload this page.' );
                    setProgress( 'Queue disconnected. Reload this page.' );
                } finally {
                    dashboardPollRunning = false;
                }
            };

            heartbeat().catch( () => {} );
            window.setInterval( () => {
                heartbeat().catch( () => {
                    setProgress( 'Queue disconnected. Reload this page.' );
                } );
            }, 15000 );

            window.setTimeout( processDashboardQueue, 250 );
            window.setInterval( processDashboardQueue, 5000 );
        }() );
        JS;
}
