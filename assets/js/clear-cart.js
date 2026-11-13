( function () {
	'use strict';

	var config = window.phpavelSfcClearCart;

	if ( ! config || ! config.ajaxUrl || ! config.nonce ) {
		return;
	}

	function release( link ) {
		link.removeAttribute( 'data-busy' );
		link.removeAttribute( 'aria-disabled' );
	}

	function clearCart( link ) {
		if ( '1' === link.getAttribute( 'data-busy' ) ) {
			return;
		}

		link.setAttribute( 'data-busy', '1' );
		link.setAttribute( 'aria-disabled', 'true' );

		var body = new window.URLSearchParams();
		body.set( 'action', 'phpavel_sfc_clear_cart' );
		body.set( 'nonce', config.nonce );

		window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: body.toString()
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				if ( result && result.success ) {
					window.location.reload();
					return;
				}

				release( link );
			} )
			.catch( function () {
				release( link );
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		if ( event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}

		var target = event.target;

		if ( ! target || ! target.closest ) {
			return;
		}

		var link = target.closest( '[data-phpavel-sfc-clear]' );

		if ( ! link ) {
			return;
		}

		event.preventDefault();
		clearCart( link );
	} );
}() );