/**
 * gallery.js — Blusiast Gallery Page
 *
 * Features:
 *  - Lightbox (open, close, keyboard nav, prev/next within same context)
 *  - Event filter pills
 *  - Comment display from pre-serialised JSON
 *  - AJAX comment submission (logged-in users only)
 *  - Smooth scroll into view on filter change
 */

( function () {
  'use strict';

  /* ── State ───────────────────────────────── */
  let commentData   = {};        // { "img-id": [ {id, author, avatar, date, content}, … ] }
  let currentItems  = [];        // NodeList of .gallery-item in the active context
  let currentIndex  = -1;        // index within currentItems

  /* ── DOM refs ─────────────────────────────── */
  const lightbox    = document.getElementById( 'gl-lightbox' );
  const backdrop    = document.getElementById( 'gl-lb-backdrop' );
  const lbImg       = document.getElementById( 'gl-lb-img' );
  const lbCaption   = document.getElementById( 'gl-lb-caption' );
  const lbEventTag  = document.getElementById( 'gl-lb-event-tag' );
  const lbClose     = document.getElementById( 'gl-lb-close' );
  const lbPrev      = document.getElementById( 'gl-lb-prev' );
  const lbNext      = document.getElementById( 'gl-lb-next' );
  const commentList = document.getElementById( 'gl-lb-comment-list' );
  const submitBtn   = document.getElementById( 'gl-comment-submit' );
  const commentText = document.getElementById( 'gl-comment-text' );
  const commentImgId= document.getElementById( 'gl-comment-img-id' );
  const commentNonce= document.getElementById( 'gl-comment-nonce' );
  const commentPageId=document.getElementById( 'gl-comment-page-id' );
  const commentStatus=document.getElementById( 'gl-comment-status' );

  /* ── Boot ─────────────────────────────────── */
  function init() {
    loadCommentData();
    bindGalleryItems();
    bindFilters();
    bindLightboxControls();
    if ( submitBtn ) bindCommentSubmit();
  }

  /* ── Load pre-serialised comment data ─────── */
  function loadCommentData() {
    const el = document.getElementById( 'gallery-comment-data' );
    if ( el ) {
      try { commentData = JSON.parse( el.textContent ); } catch(e) {}
    }
  }

  /* ── Bind gallery item clicks ─────────────── */
  function bindGalleryItems() {
    document.querySelectorAll( '.gallery-item' ).forEach( item => {
      item.addEventListener( 'click', function () {
        const section = this.closest( '.gallery-event-block' ) || document.getElementById( 'general-grid' );
        currentItems = section.querySelectorAll( '.gallery-item' );
        currentIndex = Array.from( currentItems ).indexOf( this );
        openLightbox( this );
      } );
    } );
  }

  /* ── Open lightbox ────────────────────────── */
  function openLightbox( item ) {
    const src       = item.dataset.src;
    const caption   = item.dataset.caption || '';
    const eventName = item.dataset.eventName || '';
    const imgId     = item.dataset.imgId || '';

    lbCaption.textContent  = caption;
    lbEventTag.textContent = eventName;

    lbImg.classList.add( 'is-loading' );
    lbImg.src = src;
    lbImg.onload = () => lbImg.classList.remove( 'is-loading' );

    if ( commentImgId ) commentImgId.value = imgId;
    if ( commentText ) commentText.value = '';
    if ( commentStatus ) { commentStatus.textContent = ''; commentStatus.className = 'gl-lightbox__comment-status'; }

    renderComments( imgId );
    updateArrows();

    lightbox.hidden  = false;
    backdrop.hidden  = false;
    document.body.style.overflow = 'hidden';

    lightbox.focus();
  }

  /* ── Close lightbox ───────────────────────── */
  function closeLightbox() {
    lightbox.hidden = true;
    backdrop.hidden = true;
    document.body.style.overflow = '';
    currentItems = [];
    currentIndex = -1;
  }

  /* ── Navigate ─────────────────────────────── */
  function goPrev() {
    if ( currentIndex > 0 ) {
      currentIndex--;
      openLightbox( currentItems[ currentIndex ] );
    }
  }
  function goNext() {
    if ( currentIndex < currentItems.length - 1 ) {
      currentIndex++;
      openLightbox( currentItems[ currentIndex ] );
    }
  }
  function updateArrows() {
    if ( lbPrev ) lbPrev.disabled = currentIndex <= 0;
    if ( lbNext ) lbNext.disabled = currentIndex >= currentItems.length - 1;
  }

  /* ── Render comments ──────────────────────── */
  function renderComments( imgId ) {
    if ( !commentList ) return;
    const comments = commentData[ imgId ] || [];
    if ( comments.length === 0 ) {
      commentList.innerHTML = '<p class="gl-lightbox__no-comments">No comments yet — be the first!</p>';
      return;
    }
    commentList.innerHTML = comments.map( c => `
      <div class="gl-comment">
        <img class="gl-comment__avatar" src="${ escHtml( c.avatar ) }" alt="${ escHtml( c.author ) }" width="32" height="32">
        <div class="gl-comment__body">
          <div class="gl-comment__header">
            <span class="gl-comment__author">${ escHtml( c.author ) }</span>
            <span class="gl-comment__date">${ escHtml( c.date ) }</span>
          </div>
          <p class="gl-comment__text">${ escHtml( c.content ) }</p>
        </div>
      </div>
    ` ).join( '' );
  }

  /* ── Submit comment via AJAX ──────────────── */
  function bindCommentSubmit() {
    submitBtn.addEventListener( 'click', async function () {
      const text  = commentText ? commentText.value.trim() : '';
      const imgId = commentImgId ? commentImgId.value : '';
      const nonce = commentNonce ? commentNonce.value : '';
      const postId= commentPageId ? commentPageId.value : '';

      if ( !text ) {
        showStatus( 'Please write a comment first.', 'error' );
        return;
      }

      submitBtn.disabled   = true;
      submitBtn.textContent= 'Posting…';

      const body = new FormData();
      body.append( 'action',                 'blusiast_gallery_comment' );
      body.append( 'nonce',                  nonce );
      body.append( 'post_id',                postId );
      body.append( 'gallery_image_id',       imgId );
      body.append( 'comment_content',        text );

      try {
        const res  = await fetch( blusiast_ajax.ajax_url, { method: 'POST', body } );
        const data = await res.json();

        if ( data.success ) {
          /* Add new comment to local data */
          if ( !commentData[ imgId ] ) commentData[ imgId ] = [];
          commentData[ imgId ].push( data.data );
          renderComments( imgId );
          if ( commentText ) commentText.value = '';
          showStatus( 'Comment posted!', 'success' );

          /* Update comment count badge on the thumbnail */
          document.querySelectorAll( `.gallery-item[data-img-id="${ imgId }"] .comment-count` ).forEach( el => {
            el.textContent = commentData[ imgId ].length;
          } );
        } else {
          showStatus( data.data || 'Could not post comment.', 'error' );
        }
      } catch ( e ) {
        showStatus( 'Network error — please try again.', 'error' );
      } finally {
        submitBtn.disabled   = false;
        submitBtn.textContent= 'Post Comment';
      }
    } );
  }

  function showStatus( msg, type ) {
    if ( !commentStatus ) return;
    commentStatus.textContent = msg;
    commentStatus.className   = `gl-lightbox__comment-status is-${ type }`;
  }

  /* ── Event Filter Pills ───────────────────── */
  function bindFilters() {
    document.querySelectorAll( '.gallery-event-filter' ).forEach( btn => {
      btn.addEventListener( 'click', function () {
        /* Update pill state */
        document.querySelectorAll( '.gallery-event-filter' ).forEach( b => {
          b.classList.remove( 'archive-filter--active' );
          b.setAttribute( 'aria-selected', 'false' );
        } );
        this.classList.add( 'archive-filter--active' );
        this.setAttribute( 'aria-selected', 'true' );

        const target = this.dataset.event;
        document.querySelectorAll( '.gallery-event-block' ).forEach( block => {
          if ( target === 'all' || block.dataset.event === target ) {
            block.classList.remove( 'is-hidden' );
          } else {
            block.classList.add( 'is-hidden' );
          }
        } );

        /* Scroll to event section if a specific event was selected */
        if ( target !== 'all' ) {
          const block = document.querySelector( `.gallery-event-block[data-event="${ target }"]` );
          if ( block ) block.scrollIntoView( { behavior: 'smooth', block: 'start' } );
        }
      } );
    } );
  }

  /* ── Lightbox controls ────────────────────── */
  function bindLightboxControls() {
    /* Close button — primary exit */
    if ( lbClose ) lbClose.addEventListener( 'click', closeLightbox );

    /* Clicking the dark stage area (not the image itself) also closes */
    const stage = document.querySelector( '.gl-lightbox__stage' );
    if ( stage ) {
      stage.addEventListener( 'click', function ( e ) {
        const imgWrap = document.querySelector( '.gl-lightbox__img-wrap' );
        if ( e.target === stage || e.target === imgWrap ) {
          closeLightbox();
        }
      } );
    }

    /* Backdrop click (safety net) */
    if ( backdrop ) backdrop.addEventListener( 'click', closeLightbox );

    if ( lbPrev ) lbPrev.addEventListener( 'click', function(e) { e.stopPropagation(); goPrev(); } );
    if ( lbNext ) lbNext.addEventListener( 'click', function(e) { e.stopPropagation(); goNext(); } );

    document.addEventListener( 'keydown', function ( e ) {
      if ( !lightbox || lightbox.hidden ) return;
      if ( e.key === 'Escape'     ) { e.preventDefault(); closeLightbox(); }
      if ( e.key === 'ArrowLeft'  ) goPrev();
      if ( e.key === 'ArrowRight' ) goNext();
    } );
  }

  /* ── Tiny HTML escape ─────────────────────── */
  function escHtml( str ) {
    return String( str )
      .replace( /&/g, '&amp;' )
      .replace( /</g, '&lt;' )
      .replace( />/g, '&gt;' )
      .replace( /"/g, '&quot;' );
  }

  /* ── Run ──────────────────────────────────── */
  if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', init );
  } else {
    init();
  }

} )();
