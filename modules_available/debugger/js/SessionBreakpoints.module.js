import File                    from './File.module.js'
import Debugger                from './Debugger.module.js'
import QueuedSessionsIndicator from './QueuedSessionsIndicator.module.js'
import LanguageAbstractor      from './LanguageAbstractor.module.js'

class Breakpoint
{
	constructor( file, line, expression, id )
	{
		this.info = { file, line, expression, id };
		this.info.state = id ? 'confirmed' : 'offline';
		this.triggerStateChange();
		this.sendToDebugger();
	}

	get id()         { return this.info.id;         }
	get expression() { return this.info.expression; }
	get file()       { return this.info.file;       }
	get line()       { return this.info.line;       }
	get state()      { return this.info.state;      }
	get type()       { return this.expression ? 'conditional' : 'line' }

	triggerStateChange()
	{
		publish( 'breakpoint-state-change', { breakpoint : this } );
	}

	async sendToDebugger()
	{
		if ( !Debugger.sessionIsActive() || this.state == 'confirmed' )
		{
			return;
		}

		var filename = this.file;

		if ( File.isCodebaseRelative( filename ) )
		{
			var codebase = await QueuedSessionsIndicator.getCurrentCodebase();
			if ( codebaseb.id && codebase.root )
			{
				filename = File.convertFromCodebaseRelativePath( filename, codebase.id, codebase.root );
				// If the path is still codebase-relative, it's not a reference to a file in the
				// current codebase
				if ( File.isCodebaseRelative( filename ) )
				{
					return;
				}
			}
		}

		this.info.state = 'pending';
		this.triggerStateChange();

		var data = await Debugger.command( 'breakpoint_set', {
			type : this.type,
			line : this.line,
			file : filename,
		}, this.expression );
		this.info.id = data.parsed.id;

		this.info.state = 'confirmed'
		this.triggerStateChange();
	}

	async removeFromDebugger()
	{
		if ( Debugger.sessionIsActive() && this.state != 'removed' )
		{
			this.info.state = 'pending';
			this.triggerStateChange();

			await Debugger.command( 'breakpoint_remove', { breakpoint : this.id } );
		}

		this.info.state = 'removed'
		this.triggerStateChange();
	}

	goOffline()
	{
		delete this.info.id;
		this.info.state = 'offline';
		this.triggerStateChange();
	}
}

class SessionBreakpoints
{
	constructor()
	{
		this.allBreakpoints = {};
		subscribe( 'session-status-changed', ( e ) =>
		{
			if ( e.status == 'active' )
			{
				this.importFromDebuggerEngine()
			}
			else
			{
				this.apply( bp => bp.goOffline() );
			}
		} );
	}

	listForFile( filename )
	{
		return this.allBreakpoints[ filename ] || [];
	}

	apply( func )
	{
		for ( let file in this.allBreakpoints )
		{
			for ( let line in this.allBreakpoints[ file ] )
			{
				func( this.allBreakpoints[ file ][ line ] );
			}
		}
	}

	clearAll()
	{
		this.apply( breakpoint => this.del( breakpoint.file, breakpoint.line ) );
	}

	async importFromDebuggerEngine()
	{
		var realPathToCrp = {};
		var breakpoints = await Debugger.command( 'breakpoint_list' );
		var importEach = async bp =>
		{
			var filename = File.stripScheme( bp.filename );

			if ( typeof realPathToCrp[ filename ] == 'undefined' )
			{
				let codebase_root = await LanguageAbstractor.getCodebaseRoot( filename );
				if ( codebase_root.id && codebase_root.root )
				{
					realPathToCrp[ filename ] = File.convertToCodebaseRelativePath( filename,
						codebase_root.id, codebase_root.root );
				}
				else
				{
					realPathToCrp[ filename ] = false;
				}
			}
			if ( realPathToCrp[ filename ] )
			{
				filename = realPathToCrp[ filename ];
			}

			this.allBreakpoints[ filename ] = this.allBreakpoints[ filename ] || {};
			if ( this.allBreakpoints[ filename ][ bp.lineno ] )
			{
				this.allBreakpoints[ filename ][ bp.lineno ].info.state = 'confirmed';
				this.allBreakpoints[ filename ][ bp.lineno ].info.id    = bp.id;
			}
			else
			{
				this.allBreakpoints[ filename ][ bp.lineno ] = new Breakpoint( filename, bp.lineno,
					bp.expression || bp.expression_element, bp.id );
			}
			this.allBreakpoints[ filename ][ bp.lineno ].triggerStateChange();
		};
		let linePromises        = breakpoints.parsed.line.map( importEach );
		let conditionalPromises = breakpoints.parsed.conditional.map( importEach );

		await Promise.all( linePromises.concat( conditionalPromises ) );
		this.apply( bp => bp.sendToDebugger() );
	}

	toggle( file, line, expression )
	{
		if ( this.allBreakpoints[ file ] && this.allBreakpoints[ file ][ line ] )
		{
			this.del( file, line );
		}
		else
		{
			this.create( file, line, expression );
		}
	}

	del( file, line )
	{
		if ( ! this.allBreakpoints[ file ] || ! this.allBreakpoints[ file ][ line ] )
		{
			return;
		}
		this.allBreakpoints[ file ][ line ].removeFromDebugger();
		delete this.allBreakpoints[ file ][ line ];
	}

	create( file, line, expression )
	{
		if ( !this.allBreakpoints[ file ] )
		{
			this.allBreakpoints[ file ] = {};
		}
		if ( !this.allBreakpoints[ file ][ line ] )
		{
			this.allBreakpoints[ file ][ line ] = new Breakpoint( file, line, expression );
		}
	}

	get( file, line )
	{
		return this.allBreakpoints[ file ] && this.allBreakpoints[ file ][ line ] || null;
	}
}

var sessionBreakpoints = new SessionBreakpoints;

export default sessionBreakpoints;
