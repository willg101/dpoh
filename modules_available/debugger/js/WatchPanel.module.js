import Persistor from './Persistor.module.js'
import LanguageAbstractor from './LanguageAbstractor.module.js'

var $ = jQuery

var settings = new Persistor('watch_panel_settings')
var hasWarnedThisSession

if (!settings.expressions) {
  settings.expressions = []
}
saveExpressions()

$(document).on('click', '#watch .add-expression', function (e) {
  vTheme.showModal('Watch Expression', render('debugger.watch_expression_modal'))
})

$(document).on('keypress', '.we-expression-input', function (e) {
  if (e.which == 13 && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
    var id = $('.we-expression-input').attr('data-expression-id')
    var expression = $('.we-expression-input').text().trim()
    if (id) {
      settings.expressions[ id ] = { expression }
    } else {
      settings.expressions.push({ expression })
      id = settings.expressions.length - 1
    }
    saveExpressions()
    renderExpression(expression, id)
    vTheme.hideModal()
  }
})

$(document).on('click', '[data-watch-id] [data-role=delete]', function (e) {
  var row = $(e.target).closest('[data-watch-id]')
  var id = row.attr('data-watch-id')
  delete settings.expressions[ id ]
  saveExpressions()
  row.remove()
})

subscribe('session-status-changed', function (e) {
  if (e.status == 'active') {
    hasWarnedThisSession = false
    renderExpressions()
    $('#watch').fadeIn()
  } else {
    $('#watch').fadeOut(function () { $(this).html('') })
  }
})

subscribe('response-received', function (e) {
  if (e.parsed && e.parsed.isContinuation) {
    renderExpressions()
  }
})

$(document).on('dblclick', '#watch .display-expression', function (e) {
  var row = $(e.target).closest('[data-watch-id]')
  var id = row.attr('data-watch-id')
  vTheme.showModal('Watch Expression', render('debugger.watch_expression_modal', {
    expression: settings.expressions[ id ].expression,
    id: id
  }))
})

function notifyUserOfWatchWarning () {
  if (!settings.noNotify && !hasWarnedThisSession) {
    vTheme.notify('error', 'A watched expression failed to execute and may cause stray' +
      ' warnings or notices in your output.', '', { timeOut: 10000 })
    hasWarnedThisSession = true
  }
}

function saveExpressions () {
  settings.expressions = settings.expressions.filter((el) => el)
}

function renderExpressions () {
  $('#watch').html(render('debugger.watch_panel', {
    expressions: settings.expressions
  }))
  settings.expressions.forEach(function (expr, i) {
    evalWatchedExpression(expr.expression, $('[data-watch-id="' + i + '"] .result'))
  })
}

async function evalWatchedExpression (expression, output) {
  output = $(output)
  try {
    var result = await LanguageAbstractor.evalCommand(expression, null,
      LanguageAbstractor.NO_CREATE_SESSION)
  } catch (e) {
    if (e instanceof LanguageAbstractor.Error) {
      var message = $('<i class="fa fa-exclamation-triangle"></i>')
        .attr('title', 'No debug session is currently active')
      $(output).html(message)
      return
    } else throw e
  }

  if (output.is('.jstree')) {
    $(output).jstree('destroy')
  }

  if (result.returnValue && result.returnValue.length) {
    result.returnValue.forEach(function (item) {
      item.name = item.name || ''
      item.fullname = item.fullname || ''
    })
    $(output).html('').vtree(result.returnValue)
  } else if (result.message) {
    message = $('<i class="fa fa-exclamation-triangle"></i>').attr('title', result.message)
    $(output).html(message)
    notifyUserOfWatchWarning()
  } else {
    message = $('<i class="fa fa-exclamation-triangle"></i>')
      .attr('title', 'An empty response was received')
    $(output).html(message)
  }
}

function renderExpression (expr, id) {
  var fauxExpressions = []
  fauxExpressions[ id ] = {
    expression: expr
  }
  var existingRow = $('#watch [data-watch-id="' + id + '"]')
  if (!existingRow.length) {
    $('#watch table.watch').append(render('debugger.watch_panel', {
      noTable: true,
      expressions: fauxExpressions
    }))
  } else {
    existingRow.find('.display-expression').text(expr)
  }
  evalWatchedExpression(expr, '[data-watch-id="' + id + '"] .result')
}
