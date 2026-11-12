<?php
/**
 * Plugin Name: Captain Baltimore Homepage
 * Description: New captainbaltimore.com homepage (no Elementor): hero, news, events, pirate radio, shop, games, travel deals, newsletter. Use with the [cb_home] shortcode. v1.1 adds the sitewide brand stylesheet (cb-brand.css). v1.2 adds the Pirate Radio tuner re-skin (in cb-brand.css) and a custom homepage radio player.
 * Version: 1.2.31
 * Author: Captain Baltimore
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CB_HOME_VERSION', '1.2.31' );

/* Enqueue the stylesheet only on pages that use the shortcode. */
add_action( 'wp_enqueue_scripts', function () {
    if ( is_singular() ) {
        global $post;
        if ( $post && has_shortcode( $post->post_content, 'cb_home' ) ) {
            wp_enqueue_style(
                'cb-home',
                plugins_url( 'assets/cb-home.css', __FILE__ ),
                array(),
                CB_HOME_VERSION
            );
        }
    }
} );

/* Brand stylesheet: carries the same cartoon energy across the whole site
 * (headings, buttons, product + event cards). Loaded on every front-end page. */
add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'cb-brand',
        plugins_url( 'assets/cb-brand.css', __FILE__ ),
        array(),
        CB_HOME_VERSION
    );
} );

/* Meta pixel (v1.2.13): the "Captain Baltimore" dataset (legacy personal
 * pixel, ID 155905451618543) under Jacob's loose ad account — Jacob chose
 * to reuse it 2026-10-24. Base code + PageView sitewide; the Lead event
 * fires in the newsletter form's success handler below. */
add_action( 'wp_head', function () {
    ?>
<!-- Meta Pixel Code -->
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '155905451618543');
fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none"
src="https://www.facebook.com/tr?id=155905451618543&ev=PageView&noscript=1"
/></noscript>
<!-- End Meta Pixel Code -->
    <?php
}, 20 );

/* Borrow a fresh subscribe nonce from the legacy homepage form (same handler).
 * The legacy page (ID 21463) still renders the original signup form; its nonce
 * is time-ticked, so we cache it briefly. If the legacy page ever goes away,
 * replace this with wp_create_nonce() once the handler's action is known. */
function cb_home_legacy_nonce() {
    $cached = get_transient( 'cb_home_legacy_nonce' );
    if ( $cached ) {
        return $cached;
    }
    /* The token is a constant baked into the legacy page's stored content —
     * read it straight from the post. (v1.2.8: the old self-HTTP scrape with
     * an 8s timeout kept failing on a slow origin, and the forms degraded to
     * a fallback note — visible in the new footer band.) */
    $content = get_post_field( 'post_content', 21463 );
    if ( $content && preg_match( '/nonce:\s*"([0-9a-f]{10})"/', $content, $m ) ) {
        set_transient( 'cb_home_legacy_nonce', $m[1], DAY_IN_SECONDS );
        return $m[1];
    }
    /* Fallback: scrape the rendered page, patiently. */
    $res = wp_remote_get( home_url( '?p=21463' ), array( 'timeout' => 30 ) );
    if ( is_wp_error( $res ) ) {
        return '';
    }
    $body = wp_remote_retrieve_body( $res );
    if ( $body && preg_match( '/nonce:\s*"([0-9a-f]{10})"/', $body, $m ) ) {
        set_transient( 'cb_home_legacy_nonce', $m[1], DAY_IN_SECONDS );
        return $m[1];
    }
    return '';
}

/* Subscribe relay (v1.2.9). The site's signup handler (cb_subscribe_submit)
 * only answers LOGGED-OUT requests, so the browser form — which carries the
 * admin's cookies when Jacob tests it himself — got a bare "0" back and
 * showed a generic error even though the same request succeeds for every
 * real visitor. The form now posts to this relay instead; the relay replays
 * the request server-side with no cookies (anonymous, exactly like a
 * visitor) with the signup token injected, and returns the handler's JSON. */
function cb_home_subscribe_relay() {
    $token = cb_home_legacy_nonce();
    if ( ! $token ) {
        wp_send_json( array( 'success' => false ) );
    }
    $res = wp_remote_post( admin_url( 'admin-ajax.php' ), array(
        'timeout' => 25,
        'body'    => array(
            'action' => 'cb_subscribe_submit',
            'nonce'  => $token,
            'list'   => 'default',
            'email'  => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
            'web'    => isset( $_POST['web'] ) ? sanitize_text_field( wp_unslash( $_POST['web'] ) ) : '',
        ),
    ) );
    if ( is_wp_error( $res ) ) {
        wp_send_json( array( 'success' => false ) );
    }
    $data = json_decode( wp_remote_retrieve_body( $res ), true );
    wp_send_json( is_array( $data ) ? $data : array( 'success' => false ) );
}
add_action( 'wp_ajax_cb_home_subscribe', 'cb_home_subscribe_relay' );
add_action( 'wp_ajax_nopriv_cb_home_subscribe', 'cb_home_subscribe_relay' );

/* Newsletter form (posts to the relay above; the token stays server-side). */
function cb_home_newsletter_form() {
    static $n = 0;
    $n++;
    $id   = 'cbhome-sub-' . $n;
    $ajax = esc_url( admin_url( 'admin-ajax.php' ) );

    ob_start();
    ?>
    <form id="<?php echo esc_attr( $id ); ?>" class="cb-home-sub" novalidate>
        <input type="email" name="cbsub_email" class="cb-home-sub__input"
            placeholder="you@baltimore.com" autocomplete="email" required />
        <input type="text" name="cbsub_web" class="cb-home-sub__hp"
            tabindex="-1" autocomplete="off" aria-hidden="true" />
        <button type="submit" class="cb-home-sub__btn">JOIN THE FUN</button>
    </form>
    <p class="cb-home-sub__msg" role="status" aria-live="polite"></p>
    <script>
    (function(){
        var form = document.getElementById(<?php echo wp_json_encode( $id ); ?>);
        if (!form) return;
        var msg = form.parentElement.querySelector('.cb-home-sub__msg');
        var btn = form.querySelector('.cb-home-sub__btn');
        form.addEventListener('submit', function(e){
            e.preventDefault();
            var email = form.cbsub_email.value.trim();
            if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
                msg.textContent = 'Please enter a valid email address.';
                msg.classList.add('is-visible');
                return;
            }
            btn.disabled = true;
            var body = new URLSearchParams({
                action: 'cb_home_subscribe',
                email: email,
                web: form.cbsub_web.value
            });
            fetch(<?php echo wp_json_encode( $ajax ); ?>, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (res && res.success) {
                    if (window.fbq) { fbq('track', 'Lead'); }
                    form.style.display = 'none';
                    msg.textContent = "You're in! Check your inbox.";
                } else {
                    msg.textContent = (res && res.data && res.data.message) || 'Something went wrong — try again.';
                }
                msg.classList.add('is-visible');
            })
            .catch(function(){
                msg.textContent = 'Network error — try again.';
                msg.classList.add('is-visible');
            })
            .finally(function(){ btn.disabled = false; });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

/* The yellow join band as a standalone shortcode — Jacob moved it into the
 * site footer (every page) on 2026-10-23: "a reminder to join the fun."
 * Styles live in cb-brand.css (sitewide sheet). */
add_shortcode( 'cb_join', function () {
    ob_start();
    ?>
    <div class="cb-joinband">
        <p class="cb-joinband__kicker">Never miss a day</p>
        <h2 class="cb-joinband__title">Get the daily guide in your inbox</h2>
        <a class="cb-joinband__btn" href="<?php echo esc_url( home_url( '/blog/' ) ); ?>">Read the latest news &rarr;</a>
        <?php echo cb_home_newsletter_form(); ?>
    </div>
    <?php
    return ob_get_clean();
} );

/* Let footer widgets render the band via [cb_join]. */
add_filter( 'widget_custom_html_content', 'do_shortcode' );
add_filter( 'widget_text_content', 'do_shortcode' );

/* Small helpers. */
function cb_home_card( $url, $img, $title, $meta = '' ) {
    $out  = '<a class="cb-card" href="' . esc_url( $url ) . '">';
    if ( $img ) {
        $out .= '<span class="cb-card__img"><img src="' . esc_url( $img ) . '" alt="" loading="lazy" /></span>';
    }
    $out .= '<span class="cb-card__body"><span class="cb-card__title">' . esc_html( $title ) . '</span>';
    if ( $meta ) {
        $out .= '<span class="cb-card__meta">' . esc_html( $meta ) . '</span>';
    }
    $out .= '</span></a>';
    return $out;
}

function cb_home_section_head( $id, $kicker, $title, $all_url, $all_label ) {
    return '<div class="cb-sec-head" id="' . esc_attr( $id ) . '">'
        . '<p class="cb-kicker">' . esc_html( $kicker ) . '</p>'
        . '<h2 class="cb-sec-title">' . esc_html( $title ) . '</h2>'
        . '<a class="cb-seeall" href="' . esc_url( $all_url ) . '">' . esc_html( $all_label ) . ' &rarr;</a>'
        . '</div>';
}

add_shortcode( 'cb_home', function () {
    ob_start();
    ?>
    <div class="cb-home">

        <!-- HERO: text only, no image, per the owner's call -->
        <section class="cb-hero" aria-labelledby="cb-hero-title">
            <p class="cb-kicker">Baltimore City Guide</p>
            <h1 id="cb-hero-title" class="cb-hero-title">Captain Baltimore<br />Adventure Awaits!</h1>
            <p class="cb-hero-sub">Daily Baltimore news, events, pirate radio, games, travel deals, and merch — all from Charm City, all pirate-approved.</p>
            <?php echo cb_home_newsletter_form(); ?>
            <p class="cb-hero-ctas">
                <a class="cb-btn" href="#cb-events">See what's on</a>
                <a class="cb-btn cb-btn--ghost" href="<?php echo esc_url( home_url( '/shop/' ) ); ?>">Shop the merch</a>
            </p>
        </section>

        <!-- NEWS -->
        <section class="cb-sec cb-sec--news" aria-labelledby="cb-news">
            <?php echo cb_home_section_head( 'cb-news', 'Fresh off the press', 'Baltimore news', home_url( '/blog/' ), 'All news' ); ?>
            <div class="cb-grid">
            <?php
            $news = new WP_Query( array(
                'posts_per_page' => 4,
                'post_status'    => 'publish',
                'category_name'  => 'community,business,education,health,music-arts',
            ) );
            if ( ! $news->have_posts() ) {
                $news = new WP_Query( array( 'posts_per_page' => 4, 'post_status' => 'publish' ) );
            }
            while ( $news->have_posts() ) {
                $news->the_post();
                echo cb_home_card(
                    get_permalink(),
                    get_the_post_thumbnail_url( get_the_ID(), 'medium_large' ),
                    get_the_title(),
                    get_the_date()
                );
            }
            wp_reset_postdata();
            ?>
            </div>
        </section>

        <!-- EVENTS -->
        <section class="cb-sec cb-sec--events" aria-labelledby="cb-events">
            <?php echo cb_home_section_head( 'cb-events', 'Get out', 'Coming up in Baltimore', home_url( '/events/' ), 'Full calendar' ); ?>
            <div class="cb-grid">
            <?php
            $today  = current_time( 'Y-m-d' );
            $events = new WP_Query( array(
                'post_type'      => 'tribe_events',
                'posts_per_page' => 4,
                'post_status'    => 'publish',
                'meta_key'       => '_EventStartDate',
                'meta_value'     => $today,
                'meta_compare'   => '>=',
                'meta_type'      => 'DATE',
                'orderby'        => 'meta_value',
                'order'          => 'ASC',
            ) );
            if ( $events->have_posts() ) {
                while ( $events->have_posts() ) {
                    $events->the_post();
                    $start = get_post_meta( get_the_ID(), '_EventStartDate', true );
                    $when  = $start ? date_i18n( 'M j', strtotime( $start ) ) : '';
                    $venue = '';
                    if ( function_exists( 'tribe_get_venue' ) ) {
                        $venue = tribe_get_venue( get_the_ID() );
                    }
                    echo cb_home_card(
                        get_permalink(),
                        get_the_post_thumbnail_url( get_the_ID(), 'medium_large' ),
                        get_the_title(),
                        trim( $when . ( $venue ? ' · ' . $venue : '' ) )
                    );
                }
            } else {
                echo '<p class="cb-home-note">Nothing on the calendar yet — <a href="' . esc_url( home_url( '/events/' ) ) . '">check the full calendar</a>.</p>';
            }
            wp_reset_postdata();
            ?>
            </div>
        </section>

        <!-- RADIO -->
        <section class="cb-sec cb-sec--radio" aria-labelledby="cb-radio">
            <?php echo cb_home_section_head( 'cb-radio', 'On the air', 'Pirate Radio — listen live', home_url( '/radio/' ), 'Open the full deck' ); ?>
            <div class="cb-hdeck" id="cb-hdeck">
                <div class="cb-hd-mast">
                    <span class="cb-hd-beacon" aria-hidden="true"></span>
                    <span class="cb-hd-call">CAPT·BLTMR — PIRATE RADIO</span>
                    <span class="cb-hd-aboard" data-aboard></span>
                </div>
                <div class="cb-hd-cards" role="group" aria-label="Channels">
                    <button type="button" class="cb-hd-card" data-slug="drill" style="--acc:#FF2E88" aria-pressed="false">
                        <span class="cb-hd-freq">87.90<small> FM</small></span>
                        <span class="cb-hd-name">Drill</span>
                        <span class="cb-hd-track" data-track>—</span>
                        <span class="cb-hd-eq" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                    </button>
                    <button type="button" class="cb-hd-card" data-slug="hip-hop-and-r-and-b" style="--acc:#7B2FFF" aria-pressed="false">
                        <span class="cb-hd-freq">90.10<small> FM</small></span>
                        <span class="cb-hd-name">Hip-Hop and R&amp;B</span>
                        <span class="cb-hd-track" data-track>—</span>
                        <span class="cb-hd-eq" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                    </button>
                    <button type="button" class="cb-hd-card" data-slug="bmore-club2" style="--acc:#00C2E8" aria-pressed="false">
                        <span class="cb-hd-freq">92.30<small> FM</small></span>
                        <span class="cb-hd-name">Rock, Punk and Alternative</span>
                        <span class="cb-hd-track" data-track>—</span>
                        <span class="cb-hd-eq" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                    </button>
                    <button type="button" class="cb-hd-card" data-slug="dock-talk" style="--acc:#FFD500" aria-pressed="false">
                        <span class="cb-hd-freq">94.10<small> FM</small></span>
                        <span class="cb-hd-name">Dock Talk</span>
                        <span class="cb-hd-track" data-track>—</span>
                        <span class="cb-hd-eq" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                    </button>
                </div>
                <div class="cb-hd-main">
                    <div class="cb-hd-artwrap"><img data-art alt="" hidden><span class="cb-hd-artfb" data-artfb>&#127988;&#8205;&#9760;&#65039;</span></div>
                    <div class="cb-hd-info">
                        <span class="cb-hd-live" data-live>ON AIR</span>
                        <p class="cb-hd-title" data-title>Tuning&hellip;</p>
                        <p class="cb-hd-artist" data-artist>&mdash;</p>
                        <div class="cb-hd-progress"><div data-bar></div></div>
                        <div class="cb-hd-times"><span data-elapsed>0:00</span><span data-duration>&ndash;:––</span></div>
                    </div>
                </div>
                <div class="cb-hd-transport">
                    <button type="button" class="cb-hd-seek" data-prev aria-label="Previous channel">&#9664;&#9664;</button>
                    <button type="button" class="cb-hd-play" data-play aria-label="Play"><svg viewBox="0 0 24 24" width="30" height="30" aria-hidden="true"><path d="M7 4l13 8-13 8z" fill="currentColor"/></svg></button>
                    <button type="button" class="cb-hd-seek" data-next aria-label="Next channel">&#9654;&#9654;</button>
                    <div class="cb-hd-vol">
                        <button type="button" class="cb-hd-volbtn" data-mute aria-label="Mute"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3a3.5 3.5 0 0 0-2-3.15v6.3a3.5 3.5 0 0 0 2-3.15z" fill="currentColor"/></svg></button>
                        <input type="range" class="cb-hd-volrange" data-volume min="0" max="1" step="0.01" value="0.8" aria-label="Volume">
                    </div>
                </div>
                <audio data-audio preload="none"></audio>
            </div>
            <script>
            (function(){
                var root = document.getElementById('cb-hdeck');
                if (!root) return;
                var CHANNELS = [
                    { slug: 'drill', stream: 'https://radio.captainbaltimore.com/listen/local_baltimore_hip_hop_and_rb/radio.mp3' },
                    { slug: 'hip-hop-and-r-and-b', stream: 'https://radio.captainbaltimore.com/listen/local_baltimore_hip_hop_and_rb/radio.mp3' },
                    { slug: 'bmore-club2', stream: 'https://radio.captainbaltimore.com/listen/captainbaltimore/radio.mp3' },
                    { slug: 'dock-talk', stream: 'https://radio.captainbaltimore.com/listen/dock_talk/radio.mp3' }
                ];
                var ICON_PLAY = '<svg viewBox="0 0 24 24" width="30" height="30" aria-hidden="true"><path d="M7 4l13 8-13 8z" fill="currentColor"/></svg>';
                var ICON_PAUSE = '<svg viewBox="0 0 24 24" width="30" height="30" aria-hidden="true"><path d="M6 4h4v16H6zM14 4h4v16h-4z" fill="currentColor"/></svg>';
                var ICON_VOL = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3a3.5 3.5 0 0 0-2-3.15v6.3a3.5 3.5 0 0 0 2-3.15z" fill="currentColor"/></svg>';
                var ICON_MUTE = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M3 9v6h4l5 5V4L7 9H3zm14.6 3l2.2-2.2-1.4-1.4-2.2 2.2-2.2-2.2-1.4 1.4L14.8 12l-2.2 2.2 1.4 1.4 2.2-2.2 2.2 2.2 1.4-1.4L17.6 12z" fill="currentColor"/></svg>';
                function lsGet(k){ try { return localStorage.getItem(k); } catch(e){ return null; } }
                function lsSet(k,v){ try { if (v == null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch(e){} }
                function fmt(sec){ if (sec == null || isNaN(sec) || sec < 0) return '–:––'; sec = Math.floor(sec); var m = Math.floor(sec/60), s = sec % 60; return m + ':' + (s < 10 ? '0' : '') + s; }
                function q(sel){ return root.querySelector(sel); }
                var audio = q('[data-audio]'), playBtn = q('[data-play]');
                var cards = {}; root.querySelectorAll('.cb-hd-card').forEach(function(c){ cards[c.dataset.slug] = c; });
                var state = { data: {}, slug: lsGet('cbdChannel') || 'drill', playing: false, fetchedAt: 0 };
                if (!cards[state.slug]) state.slug = 'drill';
                function streamFor(slug){ var d = state.data[slug]; if (d && d.stream) return d.stream; for (var i=0;i<CHANNELS.length;i++) if (CHANNELS[i].slug===slug) return CHANNELS[i].stream; return ''; }
                function idx(slug){ for (var i=0;i<CHANNELS.length;i++) if (CHANNELS[i].slug===slug) return i; return 0; }
                var savedVol = parseFloat(lsGet('cbdVolume')); audio.volume = isNaN(savedVol) ? 0.8 : savedVol;
                q('[data-volume]').value = audio.volume;
                if (lsGet('cbdMuted') === '1') audio.muted = true;
                function paintMute(){ q('[data-mute]').innerHTML = (audio.muted || audio.volume === 0) ? ICON_MUTE : ICON_VOL; }
                paintMute();
                function paintPlay(){
                    playBtn.innerHTML = state.playing ? ICON_PAUSE : ICON_PLAY;
                    playBtn.setAttribute('aria-label', state.playing ? 'Pause' : 'Play');
                    playBtn.classList.toggle('is-playing', state.playing);
                    root.classList.toggle('is-playing', state.playing);
                    root.querySelectorAll('.cb-hd-card').forEach(function(c){ c.classList.toggle('is-sounding', state.playing && c.dataset.slug === state.slug); });
                    syncMediaSession();
                }
                function loadPlay(){
                    var url = streamFor(state.slug); if (!url) return;
                    if (audio.dataset.stream !== url) { audio.src = url; audio.dataset.stream = url; }
                    audio.play().then(function(){ state.playing = true; paintPlay(); }).catch(function(){ state.playing = false; paintPlay(); });
                }
                function stop(){
                    audio.pause(); audio.removeAttribute('src'); audio.dataset.stream = ''; audio.load();
                    state.playing = false; paintPlay();
                }
                playBtn.addEventListener('click', function(){ state.playing ? stop() : loadPlay(); });
                audio.addEventListener('error', function(){ if (state.playing) { state.playing = false; paintPlay(); } });
                function tune(slug, keep){
                    if (!cards[slug]) return;
                    state.slug = slug; lsSet('cbdChannel', slug);
                    root.querySelectorAll('.cb-hd-card').forEach(function(c){
                        var on = c.dataset.slug === slug;
                        c.classList.toggle('is-tuned', on);
                        c.setAttribute('aria-pressed', on ? 'true' : 'false');
                    });
                    render();
                    if (keep) loadPlay();
                }
                root.querySelectorAll('.cb-hd-card').forEach(function(c){ c.addEventListener('click', function(){ if (c.dataset.slug !== state.slug) tune(c.dataset.slug, state.playing); }); });
                q('[data-prev]').addEventListener('click', function(){ tune(CHANNELS[(idx(state.slug) + CHANNELS.length - 1) % CHANNELS.length].slug, state.playing); });
                q('[data-next]').addEventListener('click', function(){ tune(CHANNELS[(idx(state.slug) + 1) % CHANNELS.length].slug, state.playing); });
                q('[data-volume]').addEventListener('input', function(){ audio.volume = parseFloat(this.value); audio.muted = false; lsSet('cbdVolume', audio.volume); lsSet('cbdMuted', null); paintMute(); });
                q('[data-mute]').addEventListener('click', function(){ audio.muted = !audio.muted; lsSet('cbdMuted', audio.muted ? '1' : null); paintMute(); });
                /* ---------- media session (lock screen / OS media controls) ---------- */
                // v1.2.15: the same lock-screen feed the full deck got in
                // cb-radio-deck v1.1.15. syncMediaSession() is called from
                // paintPlay() (play/pause flips) and render() (every poll /
                // retune), and reads state.data[state.slug] for the tuned
                // — i.e. playing — channel. The album text comes from the
                // tuned card itself, so it always matches what the homepage
                // shows. Fully feature-detected and exception-proof: a
                // browser without the API just skips all of this.
                var MS = ('mediaSession' in navigator) ? navigator.mediaSession : null;
                var msEngaged = false, msKey = '';
                function syncMediaSession(){
                    if (!MS) return;
                    try {
                        if (state.playing) {
                            var d = state.data[state.slug];
                            if (d) {
                                var card = cards[state.slug];
                                var freqTxt = '', nameTxt = '';
                                if (card) {
                                    var fEl = card.querySelector('.cb-hd-freq'), nEl = card.querySelector('.cb-hd-name');
                                    if (fEl) freqTxt = fEl.textContent.replace(/\s+/g, ' ').trim();
                                    if (nEl) nameTxt = nEl.textContent.replace(/\s+/g, ' ').trim();
                                }
                                var msTitle = (d.song && d.song.title) || '';
                                var msArtist = (d.artist && d.artist.name) || (d.song && d.song.artist) || '';
                                var msArt = (d.song && d.song.art) || (d.artist && d.artist.photo) || '';
                                var key = state.slug + '|' + msTitle + '|' + msArtist + '|' + msArt;
                                if (key !== msKey && typeof MediaMetadata !== 'undefined') {
                                    msKey = key;
                                    var md = {
                                        title: msTitle || 'Pirate Radio',
                                        artist: msArtist || 'Captain Baltimore',
                                        album: (freqTxt + (nameTxt ? ' · ' + nameTxt : '')).trim()
                                    };
                                    if (msArt) {
                                        md.artwork = [96, 128, 192, 256, 384, 512].map(function(s){ return { src: msArt, sizes: s + 'x' + s }; });
                                    }
                                    MS.metadata = new MediaMetadata(md);
                                }
                                msEngaged = true;
                            }
                            MS.playbackState = 'playing';
                        } else if (msEngaged) {
                            // The mini deck's stop tears the stream down
                            // (stop()), so a pause here is a true stop:
                            // clear the lock-screen entry.
                            msEngaged = false;
                            msKey = '';
                            try { MS.metadata = null; } catch(e){}
                            MS.playbackState = 'none';
                        }
                    } catch(e){ /* media session must never break playback */ }
                }
                if (MS) {
                    var msHandlers = {
                        play: function(){ if (!state.playing) loadPlay(); },
                        pause: function(){ if (state.playing) stop(); },
                        stop: function(){ if (state.playing) stop(); },
                        previoustrack: function(){ tune(CHANNELS[(idx(state.slug) + CHANNELS.length - 1) % CHANNELS.length].slug, state.playing); },
                        nexttrack: function(){ tune(CHANNELS[(idx(state.slug) + 1) % CHANNELS.length].slug, state.playing); }
                    };
                    Object.keys(msHandlers).forEach(function(a){
                        try { MS.setActionHandler(a, msHandlers[a]); } catch(e){}
                    });
                }
                function render(){
                    CHANNELS.forEach(function(ch){
                        var d = state.data[ch.slug], el = cards[ch.slug];
                        if (el && d && d.song) el.querySelector('[data-track]').textContent = (d.song.title || '—') + (d.song.artist ? ' — ' + d.song.artist : '');
                    });
                    var d = state.data[state.slug]; if (!d) return;
                    q('[data-title]').textContent = (d.song && d.song.title) || '—';
                    q('[data-artist]').textContent = (d.artist && d.artist.name) || (d.song && d.song.artist) || '—';
                    q('[data-aboard]').textContent = (typeof d.listeners === 'number' ? d.listeners + ' aboard' : '') + (d.demo ? ' · DEMO SIGNAL' : '');
                    q('[data-live]').textContent = d.live ? 'ON AIR' : 'SIGNAL DOWN';
                    var art = (d.song && d.song.art) || (d.artist && d.artist.photo) || '';
                    var img = q('[data-art]');
                    if (art) { img.src = art; img.hidden = false; q('[data-artfb]').style.display = 'none'; }
                    else { img.hidden = true; q('[data-artfb]').style.display = ''; }
                    tick();
                    syncMediaSession();
                }
                function tick(){
                    var d = state.data[state.slug];
                    if (!d || d.elapsed == null || !d.duration) { q('[data-bar]').style.width = '0%'; q('[data-elapsed]').textContent = '0:00'; q('[data-duration]').textContent = '–:––'; return; }
                    var el = d.elapsed + (Date.now() - state.fetchedAt) / 1000;
                    if (el >= d.duration) { q('[data-bar]').style.width = '100%'; return; }
                    q('[data-bar]').style.width = Math.min(100, el / d.duration * 100) + '%';
                    q('[data-elapsed]').textContent = fmt(el);
                    q('[data-duration]').textContent = fmt(d.duration);
                }
                function fetchState(){
                    fetch('/wp-json/cb-deck/v1/state').then(function(r){ return r.json(); }).then(function(res){
                        if (!res || !res.channels) return;
                        res.channels.forEach(function(c){ state.data[c.slug] = c; });
                        state.fetchedAt = Date.now();
                        render();
                    }).catch(function(){});
                }
                tune(state.slug, false);
                paintPlay();
                fetchState();
                setInterval(function(){ if (!document.hidden) fetchState(); }, 15000);
                setInterval(tick, 1000);
            })();
            </script>
            <p class="cb-radio-note">Every channel, live from the homepage. The <a href="<?php echo esc_url( home_url( '/radio/' ) ); ?>">full deck</a> has votes, the Top 10, and recently played.</p>
        </section>

        <!-- SHOP -->
        <section class="cb-sec cb-sec--shop" aria-labelledby="cb-shop">
            <?php echo cb_home_section_head( 'cb-shop', 'Wear it proud', 'From the shop', home_url( '/shop/' ), 'Visit the shop' ); ?>
            <div class="cb-grid">
            <?php
            $prods = get_posts( array(
                'post_type'      => 'product',
                'posts_per_page' => 4,
                'post_status'    => 'publish',
                'meta_key'       => 'total_sales',
                'orderby'        => 'meta_value_num',
                'order'          => 'DESC',
            ) );
            foreach ( $prods as $p ) {
                $price = '';
                if ( function_exists( 'wc_get_product' ) ) {
                    $prod  = wc_get_product( $p->ID );
                    $price = $prod ? $prod->get_price_html() : '';
                }
                echo cb_home_card(
                    get_permalink( $p->ID ),
                    get_the_post_thumbnail_url( $p->ID, 'medium_large' ),
                    $p->post_title,
                    $price ? wp_strip_all_tags( $price ) : ''
                );
            }
            ?>
            </div>
        </section>

        <!-- GAMES -->
        <section class="cb-sec cb-sec--games" aria-labelledby="cb-games">
            <?php echo cb_home_section_head( 'cb-games', 'Play time', 'Fun &amp; Games', home_url( '/street-empire/' ), 'Play Crabs in a Barrel' ); ?>
            <div class="cb-grid cb-grid--3">
                <a class="cb-card cb-card--game" href="<?php echo esc_url( home_url( '/street-empire/' ) ); ?>">
                    <span class="cb-card__body"><span class="cb-card__title">Crabs in a Barrel</span>
                    <span class="cb-card__meta">Our Baltimore street game — climb the leaderboard.</span></span>
                </a>
                <a class="cb-card cb-card--game" href="<?php echo esc_url( home_url( '/dice-101/' ) ); ?>">
                    <span class="cb-card__body"><span class="cb-card__title">Dice 101</span>
                    <span class="cb-card__meta">Learn the game, then roll with the crew.</span></span>
                </a>
                <a class="cb-card cb-card--game" href="<?php echo esc_url( home_url( '/shop/' ) ); ?>">
                    <span class="cb-card__body"><span class="cb-card__title">Coloring Books</span>
                    <span class="cb-card__meta">Baltimore scenes to color — browse the shop.</span></span>
                </a>
            </div>
        </section>

        <!-- TRAVEL DEALS -->
        <section class="cb-sec cb-sec--deals" aria-labelledby="cb-deals">
            <?php echo cb_home_section_head( 'cb-deals', 'Get away', 'Travel deals from Baltimore', home_url( '/travel-deals/' ), 'All travel deals' ); ?>
            <div class="cb-grid">
            <?php
            $deals = new WP_Query( array(
                'cat'            => 123,
                'posts_per_page' => 3,
                'post_status'    => 'publish',
            ) );
            if ( $deals->have_posts() ) {
                while ( $deals->have_posts() ) {
                    $deals->the_post();
                    echo cb_home_card(
                        get_permalink(),
                        get_the_post_thumbnail_url( get_the_ID(), 'medium_large' ),
                        get_the_title(),
                        get_the_date()
                    );
                }
            } else {
                echo '<p class="cb-home-note">The latest Baltimore flight and cruise deals live on the <a href="' . esc_url( home_url( '/travel-deals/' ) ) . '">travel deals page</a>.</p>';
            }
            wp_reset_postdata();
            ?>
            </div>
        </section>

    </div>
    <?php
    return ob_get_clean();
} );
