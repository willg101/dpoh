import File from './File.module.js'
import ProgrammingLanguage from './ProgrammingLanguage.module.js'

var $ = jQuery;

// $.type( async function(){} ) returns 'object', not 'function', and this messes up calls like
// $( '#console' ).terminal( async function( command, term ){ ... } ). Let's fix that.
var AsyncFunction = (async function(){}).constructor;
var old_method = $.type;
$.type = function( item )
{
	if ( typeof item == 'function' && item instanceof AsyncFunction )
	{
		return 'function';
	}
	return old_method.call( $, item );
};

/**
 * Control the console output for a single command
 */
class ConsoleCommandDisplay
{
	/**
	 * @param jQueryTerminal term
	 */
	constructor( term )
	{
		this.id = 'ccd_' + this.constructor.id_ctr++;
		var inner_fn = () => `<div id="${this.id}"></div>`;
		term.echo( () => inner_fn(), { raw : true } );

		this.element = $( `#${this.id}` );
		this.spinner = $( '<i class="fa fa-spin fa-circle-o-notch"></i>' );
		this.element.append( this.spinner );
	}

	/**
	 * @param string text
	 * @param string level ('error' is the only non-default value accepted right now)
	 * @retval jQuery
	 */
	makeText( text, level )
	{
		return $( '<div>' ).text( text ).css( 'color', level == 'error' ? '#f46242' : '' );
	}

	/**
	 * @brief
	 *	Append a line of output
	 *
	 * @param string text
	 * @param string level @see makeText()
	 */
	append( text, level )
	{
		this.element.append( this.makeText( text, level ) );
	}

	/**
	 * @brief
	 *	Prepend a line of output
	 *
	 * @param string text
	 * @param string level @see makeText()
	 */
	prepend( text, level )
	{
		this.element.prepend( this.makeText( text, level ) );
	}

	/**
	 * @param jQuery|string replacement
	 */
	replaceSpinner( replacement )
	{
		this.spinner.after( replacement ).remove();
	}

	removeSpinner()
	{
		this.spinner.remove();
	}
}

ConsoleCommandDisplay.id_ctr = 0; 

// Initialize the console
subscribe( 'vortex-init', function()
{
	$( '#console' ).terminal( async function( command, term )
	{ 
		term.pause();

		var display = new ConsoleCommandDisplay( term );
		var result = await ProgrammingLanguage.tx( 'evalCommand', command, display );

		if ( result.message )
		{
			display.removeSpinner();
			display.append( result.message, result.status );
		}
		else
		{

			display.replaceSpinner( $( '<div>' ).vtree( result.return_value ) );
		}

		term.resume();
	},

	$.extend( ProgrammingLanguage.tx( 'getConsoleInfo' ), {
		name : 'console',
		enabled : false,
	} ) );

	$.terminal.defaults.formatters.push( ProgrammingLanguage.tx( 'getConsoleFormatter' ) );

	// jQuery Terminal's handling of resizing, in which all messages are re-rendered, does not
	// work well with jsTree, and tends to crash. Since we wouldn't gain much benefit from this
	// feature even if it did work, let's just disable it.
	$( '#console' ).resizer( 'unbind' );
} );

// Focus the console when the console window is clicked
$( document ).on( 'click', '#console_container', function()
{
	$( '#console' ).terminal().enable();
} );
