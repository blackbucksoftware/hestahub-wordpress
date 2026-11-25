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
	var request = null;
	var live = null;

	if ( ! window.fetch || ! window.DOMParser || ! window.history || ! window.history.pushState || ! Element.prototype.closest ) {
		return;
	}

	/**
	 * The calendars in a document, in order.
	 *
	 * @param {Document} root The document.
	 * @return {Element[]} The calendars.
	 */
	function calendars( root ) {
		return Array.prototype.slice.call( root.querySelectorAll( CALENDAR ) );
	}

	/**
	 * History state with ours added, keeping what a theme or plugin stored.
	 *
	 * @return {Object} The state.
	 */
	function state() {
		var merged = {};
		var key;
		var current = window.history.state;
		if ( current && 'object' === typeof current ) {
			for ( key in current ) {
				if ( Object.prototype.hasOwnProperty.call( current, key ) ) {
					merged[ key ] = current[ key ];
				}
			}
		}
		merged.hestahub = true;
		return merged;
	}

	/**
	 * Tells screen readers which months are now shown. The live region
	 * exists from the start: one added already filled is often not read.
	 *
	 * @param {Element} calendar The calendar the visitor used.
	 */
	function announce( calendar ) {
		live.textContent = Array.prototype.map.call( calendar.querySelectorAll( 'caption' ), function ( caption ) {
			return caption.textContent.trim();
		} ).join( ', ' );
	}

	/**
	 * Moves focus to the link the visitor used, in its new calendar, or to
	 * the calendar if there is no such link (the end of the window).
	 *
	 * @param {Element}     calendar The new calendar.
	 * @param {string|null} rel      The used link's rel: prev or next.
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
	 * @param {string}       url  The page to take the calendars from.
	 * @param {Object|null}  used From a click: index (which calendar) and rel
	 *                            (of the link), to add a history entry and
	 *                            refocus. Null from Back or Forward.
	 */
	function swap( url, used ) {
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
				var swapped;
				var calendar;
				if ( fresh.length !== current.length ) {
					throw new Error( 'Calendars differ' );
				}
				swapped = current.map( function ( old, i ) {
					var replacement = document.importNode( fresh[ i ], true );
					old.parentNode.replaceChild( replacement, old );
					return replacement;
				} );
				request = null;
				calendar = swapped[ used ? Math.min( used.index, swapped.length - 1 ) : 0 ];
				if ( used ) {
					window.history.pushState( state(), '', url );
					refocus( calendar, used.rel );
				}
				announce( calendar );
			} )
			.catch( function ( error ) {
				if ( error && 'AbortError' === error.name ) {
					return;
				}
				window.location.assign( url );
			} );
	}

	if ( ! calendars( document ).length ) {
		return;
	}

	live = document.createElement( 'div' );
	live.className = 'hestahub-live';
	live.setAttribute( 'aria-live', 'polite' );
	document.body.appendChild( live );

	// So Back to the first months is a state this script restores, too.
	window.history.replaceState( state(), '', window.location.href );

	document.addEventListener( 'click', function ( event ) {
		var link;
		var calendar;
		if ( event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}
		link = event.target.closest( LINK );
		if ( ! link || link.origin !== window.location.origin || ( link.target && '_self' !== link.target ) ) {
			return;
		}
		calendar = link.closest( CALENDAR );
		if ( ! calendar ) {
			return;
		}
		event.preventDefault();
		swap( link.href, { index: calendars( document ).indexOf( calendar ), rel: link.getAttribute( 'rel' ) } );
	} );

	window.addEventListener( 'popstate', function ( event ) {
		if ( event.state && event.state.hestahub ) {
			swap( window.location.href, null );
		}
	} );
}() );
