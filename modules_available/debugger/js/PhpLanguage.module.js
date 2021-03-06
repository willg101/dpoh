import LanguageAbstractor from './LanguageAbstractor.module.js'
import Debugger from './Debugger.module.js'
import WsClient from './WsClient.module.js'

const MAGIC_EVAL_VAR_NAME = '$__'
const HEREDOC_PREFIX = 'eval(<<<\'VORTEXEVAL\'\n'
const HEREDOC_SUFFIX = '\nreturn ' + MAGIC_EVAL_VAR_NAME + ';\nVORTEXEVAL\n);'
const EVAL_MAGIC_VAR_REGEX = new RegExp('\\' + MAGIC_EVAL_VAR_NAME + '($|[^_\\w])')
const DUMMY_SESSION_TIMEOUT_MS = 500

class PhpLanguage extends LanguageAbstractor {
  /// ////////////////////////////////////////////////////////////////////////////////////////////
  // Public/required functions
  /// ////////////////////////////////////////////////////////////////////////////////////////////

  async getCodebaseRoot (file) {
    file = escapeDoubleQuotes(file)

    await Debugger.command('eval', '$__codebase_root_finder__ = ' +
      Dpoh.options.debugger.find_codebase_root)
    var root = await Debugger.command('eval', `$__codebase_root_finder__( "${file}" )`)

    var info = {}
    if (root.parsed.value && root.parsed.value[ 0 ] && root.parsed.value[ 0 ].children &&
      root.parsed.value[ 0 ].children.length) {
      root.parsed.value[ 0 ].children.forEach(el => {
        if (el.name == 'root' || el.name == 'id' || el.name == 'is_nested') {
          info[ el.name ] = el.value
        }
      })
    }
    return info
  }

  async getBytesOfMemoryUsed () {
    var data = await Debugger.command('eval', 'memory_get_usage()')
    var memData = data.parsed.value[ 0 ] || {}
    return memData.value
  }

  async getHostname () {
    if (Debugger.sessionIsActive()) {
      var data = await Debugger.command('eval', 'gethostname()')
      var hostname = data.parsed.value[ 0 ] || { value: 'unknown host' }
      return hostname.value
    } else {
      return 'localhost'
    }
  }

  async evalCommand (command, display, flags) {
    var output = function () {}
    if (display) {
      output = function (text) {
        display.prepend(text)
      }
    }

    if (!Debugger.sessionIsActive()) {
      if (flags & LanguageAbstractor.NO_CREATE_SESSION) {
        throw new this.constructor.Error('Cannot eval the command: no debug session is ' +
          'active and the `NO_CREATE_SESSION` flag is set')
      }

      try {
        output('No debug session is active; creating dummy session...')
        await this.startDummyDebugSession()
      } catch (e) {
        return {
          status: 'error',
          message: e
        }
      }
    }

    // After scouring the Xdebug protocol docs for a way to get a variable name from an
    // address, which would be required to lazy-load the response's object/array's nested
    // properties, I came up empty-handed. Lazy-loading doesn't seem to be a viable option
    // here, so let's deep-load the response instead
    Debugger.command('feature_set', { name: 'max_depth', value: 10 })
    var data = await Debugger.command('eval', this.prepareCodeForEval(command))
    Debugger.command('feature_set', { name: 'max_depth', value: 1 })

    var message = ''
    var status = 'ok'
    var returnValue

    if (data.parsed.value && data.parsed.value.length) {
      data.parsed.value.forEach(function (item) {
        item.name = item.name || ''
        item.fullname = item.fullname || ''
      })
      returnValue = data.parsed.value
    } else if (data.parsed.message) {
      message = data.parsed.message
      status = 'error'
    } else {
      message = 'Empty response received'
      status = 'error'
    }

    return { returnValue, status, message }
  }

  getConsoleInfo () {
    return {
      prompt: `${this.name}> `,
      greetings: function (cb) {
        cb('Tip: if you have trouble running a multi-statement ' +
        'snippet, try including the magic variable [[b;#21599f;]' + MAGIC_EVAL_VAR_NAME +
        ']. This will cause your code to be processed slightly differently and will ' +
        '[[b;;]output the final value of ][[b;#21599f;]' + MAGIC_EVAL_VAR_NAME + '].')
      }
    }
  }

  getConsoleFormatter () {
    var lowercase = [ '__halt_compiler', 'abstract', 'and', 'array', 'as', 'break', 'callable',
      'case', 'catch', 'class', 'clone', 'const', 'continue', 'declare', 'default', 'die',
      'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif',
      'endswitch', 'endwhile', 'eval', 'exit', 'extends', 'final', 'for', 'foreach',
      'function', 'global', 'goto', 'if', 'implements', 'include', 'include_once',
      'instanceof', 'insteadof', 'interface', 'isset', 'list', 'namespace', 'new', 'or',
      'print', 'private', 'protected', 'public', 'require', 'require_once', 'return',
      'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor'
    ]
    var allKeywords = lowercase.concat(lowercase.map(keyword => keyword.toUpperCase))

    return entireCommand => {
      return entireCommand.split(/((?:\s|&nbsp;)+)/).map(string => {
        if (allKeywords.indexOf(string) != -1) {
          return '[[;#268bd2;]' + string + ']'
        } else if (string[ 0 ] == '$' && string.length > 1) {
          return '[[;#59c203;]' + string + ']'
        } else {
          return string
        }
      }).join('')
    }
  }

  async globDirectory (dir) {
    Debugger.command('feature_set', { name: 'max_depth', value: 2 })
    var rawEntries = await this.evalCommand(`
      $__ = [];
      foreach ( glob( '${dir}*' ) as $item )
      {
          $type = is_dir( $item ) ? 'dir' : 'file';
          $__[ $item ] = [ 'type' => $type, 'name' => $item ];
      }
      return $__;`
    )
    Debugger.command('feature_set', { name: 'max_depth', value: 1 })
    return (rawEntries.returnValue[ 0 ].children || []).map(entry => {
      var ret = {};
      (entry.children || []).map(el => {
        ret[ el.name ] = el.value
      })
      return ret
    })
  }

  getLanguageNameForEditor () {
    return 'php'
  }

  /// ////////////////////////////////////////////////////////////////////////////////////////////
  // Utility functions
  /// ////////////////////////////////////////////////////////////////////////////////////////////

  prepareCodeForEval (code) {
    code = String(code)
    return code.match(EVAL_MAGIC_VAR_REGEX)
      ? HEREDOC_PREFIX + code + HEREDOC_SUFFIX
      : code
  }

  constructor (...args) {
    super('php', ...args)
    this.dummySessionTimeout = null
  }

  startDummyDebugSession () {
    var resolvePromise, rejectPromise
    var promise = new Promise((resolve, reject) => {
      resolvePromise = resolve
      rejectPromise  = reject
    })

    if (!Debugger.sessionIsActive()) {
      if (!WsClient.isConnected()) {
        rejectPromise('Error: no connection to socket server')
        return promise
      }

      var options = {
        url: 'dummy.php',
        params: {
          XDEBUG_SESSION_START: 1
        }
      }
      publish('alter-dummy-session-request', { options: options })
      var url = options.url + '?' + $.param(options.params)
      $.get(url)

      var tryProcessing = function () {
        if (!Debugger.sessionIsActive()) {
          rejectPromise('Could not initiate a new session (timed out)')
        } else {
          resolvePromise()
        }
      };
      this.dummySessionTimeout = setTimeout(tryProcessing, DUMMY_SESSION_TIMEOUT_MS)
    }
    return promise
  }
}

LanguageAbstractor.setDefault(new PhpLanguage())
