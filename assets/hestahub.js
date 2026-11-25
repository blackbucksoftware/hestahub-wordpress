/**
 * Pages the availability calendar in place: a click on one of its month
 * links fetches that link's page and swaps in its calendars, instead of
 * loading the whole page. Optional by design. Without this script, or on
 * any failure, the link loads the page as usual, which is what the contract
 * requires: https://docs.hestahub.com/v1/#routes
 *
 * It needs two classes from the calendar template, which a theme's copy
 * must keep for it to work: .hestahub-calendar and .hestahub-calendar-nav.
 *
 * @package HestaHub
 */
( function () {
	'use strict';

	var CALENDAR = '.hestahub-calendar';
	var LINK = '.hestahub-calendar-nav a';

	if ( ! window.fetch || ! window.DOMParser || ! window.history || ! window.history.pushState || ! Element.prototype.closest ) {
		return;
	}

	var request = null;
	var live = null;

	function calendars( root ) {
		return Array.prototype.slice.call( root.querySelectorAll( CALENDAR ) );
	}

	/**
	 * Tells screen readers which months are now shown.
	 */
	function announce( calendar ) {
		if ( ! live ) {
			live = document.createElement( 'div' );
			live.setAttribute( 'aria-live', 'polite' );
			live.setAttribute( 'style', 'position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);border:0;' );
			document.body.appendChild( live );
		}
		live.textContent = Array.prototype.map.call( calendar.querySelectorAll( 'caption' ), function ( caption ) {
			return caption.textContent.trim();
		} ).join( ', ' );
	}

	/**
	 * Moves focus to the link the visitor used, in its new calendar, or to
	 * the calendar if that link is gone (the end of the window).
	 */
	function refocus( calendar, rel ) {
		var link = rel ? calendar.querySelector( LINK + '[rel="' + rel + '"]' ) : null;
		if ( link ) {
			link.focus();
			return;
		}
		calendar.setAttribute( 'tabindex', '-1' );
		calendar.focus();
	}

	/**
	 * Swaps every calendar on the page for the same-position one on the page
	 * at url: the month parameter moves them all, as a reload would.
	 *
	 * @param {string}      url    The page to take the calendars from.
	 * @param {number}      index  Which calendar the visitor used.
	 * @param {string|null} rel    The link's rel (prev or next), to refocus.
	 * @param {boolean}     push   Whether to add a history entry.
	 */
	function swap( url, index, rel, push ) {
		var current = calendars( document );
		if ( ! current.length ) {
			window.location.assign( url );
			return;
		}
		if ( request ) {
			request.abort();
		}
		request = window.AbortController ? new AbortController() : null;
		current.forEach( function ( calendar ) {
			calendar.setAttribute( 'aria-busy', 'true' );
		} );

		window.fetch( url, { credentials: 'same-origin', signal: request ? request.signal : undefined } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.text();
			} )
			.then( function ( html ) {
				var fresh = calendars( new DOMParser().parseFromString( html, 'text/html' ) );
				if ( fresh.length !== current.length ) {
					throw new Error( 'Calendars differ' );
				}
				var swapped = current.map( function ( calendar, i ) {
					var replacement = document.importNode( fresh[ i ], true );
					calendar.parentNode.replaceChild( replacement, calendar );
					return replacement;
				} );
				request = null;
				if ( push ) {
					window.history.pushState( { hestahub: true }, '', url );
				}
				var used = swapped[ Math.min( index, swapped.length - 1 ) ];
				if ( rel ) {
					refocus( used, rel );
				}
				announce( used );
			} )
			.catch( function ( error ) {
				if ( error && 'AbortError' === error.name ) {
					return;
				}
				window.location.assign( url );
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		if ( event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}
		var link = event.target.closest( LINK );
		if ( ! link || link.origin !== window.location.origin || ( link.target && '_self' !== link.target ) ) {
			return;
		}
		var calendar = link.closest( CALENDAR );
		if ( ! calendar ) {
			return;
		}
		event.preventDefault();
		swap( link.href, calendars( document ).indexOf( calendar ), link.getAttribute( 'rel' ), true );
	} );

	window.addEventListener( 'popstate', function ( event ) {
		if ( event.state && event.state.hestahub ) {
			swap( window.location.href, 0, null, false );
		}
	} );

	// So Back to the first months is a state this script restores, too.
	if ( calendars( document ).length ) {
		window.history.replaceState( { hestahub: true }, '', window.location.href );
	}
}() );
