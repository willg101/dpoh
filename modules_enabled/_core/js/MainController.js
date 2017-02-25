MainController = (function( $ )
{
	var reconnect_delay_ms = 5000;
	var was_connected = true;
	var showing_no_connection_modal = false;

	$( window ).on( 'load', function()
	{
		$( "#splash" ).on( 'transitionend webkitTransitionEnd oTransitionEnd MSTransitionEnd', function()
			{
				$( '#splash' ).remove();
			} )
			.addClass( 'done' );
	} );

	function init()
	{
		dpoh.openConnection();
		var on_resize = function( pane_name )
		{
			$( document ).trigger( {
				type : 'dpoh-interface:layout-changed',
				pane : pane_name,
			} );
		};
		$( window ).on( 'load', function()
		{
			$( '.main-layout' ).layout( {
				spacing_open   : 1,
				spacing_closed : 18,
				onresize_end    : on_resize,
				customHotkeyModifier : 'CTRL',
				west : {
					customHotkey : 'o',
					initClosed   : true,
				},
				south : {
					customHotkey : 'e',
					initClosed   : true,
					size : '30%',
				},
			} );
			$( '.status-layout' ).layout( { south : { size : '50%' }, spacing_open : 1, spacing_closed: 18 } );
			window.setTimeout( on_resize.bind( undefined, '*' ), 150 );
		} );
	}

	function onConnectionStatusChanged( e )
	{
		if ( e.status == 'error' || e.status == 'closed' )
		{
			setTimeout( dpoh.openConnection, reconnect_delay_ms );
			PageTitle.update( 'status', 'disconnected' );
			if ( was_connected )
			{
				modal.set( {
					title : 'No connection',
					content : 'DPOH can\'t connect to the websocket server, which most likely means one '
						+ 'of the following: <ul><li>The server is not running</li><li>There is a '
						+ 'proxy or firewall misconfiguration</li><li>You have lost your network '
						+ 'connection</li></ul>',
					on_hide : function(){ showing_no_connection_modal = false; },
				} );
				showing_no_connection_modal = true
				modal.show();
			}
			was_connected = false;
		}
		else if ( e.status == 'connected' )
		{
			if ( showing_no_connection_modal )
			{
				//modal.hide();
			}

			// Probe for an existing session; allows us to pick up where we left off if the user
			// left the page and has now returned or lost their connection and has now regained it
			dpoh.command( 'status' );
			PageTitle.update( 'status', 'waiting' );
			was_connected = true;
		}
	}

	function onSessionStatusChanged( e )
	{
		if ( e.status == 'active' )
		{
			PageTitle.update( 'status', 'active' );
			dpoh.command( 'feature_set', { name : 'max_data',  value : 2048 } );
			dpoh.command( 'feature_set', { name : 'max_depth', value : 1 } );
			dpoh.command( 'status' );
		}
		else
		{
			PageTitle.update( 'status', ( dpoh.isConnected() ? 'waiting' : 'disconnected' ) );
		}
	}

	function onResponseReceived( e )
	{
		if ( e.is_stopping )
		{
			dpoh.command( 'run' );
		}
	}

	function onSessionInit()
	{
		dpoh.command( 'step_into' )
	}

	$( document ).on( 'dpoh:connection-status-changed',  onConnectionStatusChanged );
	$( document ).on( 'dpoh:session-status-changed',     onSessionStatusChanged );
	$( document ).on( 'dpoh:session-init',               onSessionInit );
	$( document ).on( 'dpoh:response-received',          onResponseReceived );
	$( init );

}( jQuery ));