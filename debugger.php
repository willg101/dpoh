<?php /* dpoh: ignore */


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once( 'functions.php' );

?>
<html>
	<head>
		<title>DPOH </title>
		<meta charset="UTF-8">
		<link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
		<link rel="icon" href="favicon.ico" type="image/x-icon">
		<script src="https://use.fontawesome.com/b2e4717b55.js"></script>
		<script src="//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
		<script src="http://code.jquery.com/ui/1.12.1/jquery-ui.min.js"
			integrity="sha256-VazP97ZCwtekAsvgPBSUwPFKdrwD3unUfSGVYrahUqU="
			crossorigin="anonymous"></script>
		<script src="//cdnjs.cloudflare.com/ajax/libs/jquery-layout/1.4.3/jquery.layout.min.js"></script>
		<script src="js/ace-editor/ace.js" type="text/javascript" charset="utf-8"></script>
		<script src="https://cdnjs.cloudflare.com/ajax/libs/jstree/3.2.1/jstree.min.js"></script>
		<script src="js/files.js"></script>
		<script src="js/dpoh.js"></script>
		<script src="js/modal.js"></script>
		<script src="js/MainController.js"></script>
		<script src="js/CodePanel.js"></script>
		<script src="js/intro.min.js"></script>
		<script src="js/NavPanel.js"></script>
		<script src="js/StatusPanel.js"></script>
		<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jstree/3.2.1/themes/default/style.min.css" />
		<link href="https://fonts.googleapis.com/css?family=Open+Sans" rel="stylesheet">
		<link href="https://fonts.googleapis.com/css?family=Press+Start+2P" rel="stylesheet">
		<link href="css/introjs.min.css" rel="stylesheet">
		<link rel="stylesheet" href="<?php echo compile_less() ?>" />
	</head>
	<body>
		<div class="blurable main-layout">
			<div class="ui-layout-west">
				<div id="open_files_panel" class="nav-panel">
					<div class="css-table">
						<div class="css-row toolbar">
							<div class="css-cell logo-cell match-height">
								DPOH_
							</div>
						</div>
						<div class="css-row label-row">
							<div class="css-cell">
								Files
							</div>
						</div>
						<div class="css-row">
							<div class="css-cell">
								<div class="scroller">
									<div id="file_tree" class="content">
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
			<div class="ui-layout-center">
				<div class="css-table layout-table">
					<div class="css-row toolbar">
						<div class="css-cell">
							<div class="left" data-hint="Step through code, end the current session">
								<button class="btn" data-command="step_over"><span class="fa fa-fw fa-step-forward"></span></button>
								<button class="btn" data-command="step_into"><span class="fa fa-fw fa-long-arrow-down"></span></button>
								<button class="btn" data-command="step_out"><span class="fa fa-fw fa-long-arrow-up"></span></button>
								<button class="btn" data-command="run"><span class="fa fa-fw fa-play"></span></button>
								<button class="btn" data-command="stop"><span class="fa fa-fw fa-stop"></span></button>
								<button class="btn" data-command="detach"><span class="fa fa-fw fa-unlink"></span></button>
							</div>
							<div class="right" >
								<span data-hint="Indicates the current connection status"
									data-position="bottom" id="status_indicator"
									class="fa fa-warning disconnected"></span>
							</div>
						</div>
						<div class="css-cell">
							<div class="right status-indicators blur-hidden" data-hint="Displays the current stack depth and memory usage (when a session is active)">
								<span class="h-padding"><span class="fa fa-fw fa-sort-amount-asc v-padding"></span> <span class="indicator" id="stack_depth">--</span></span>
								<span class="h-padding"><span class="fa fa-fw fa-pie-chart v-padding"></span> <span class="indicator" id="mem_usage">--</span></span>
							</div>
						</div>
					</div>
					<div class="css-row">
						<div class="css-cell editor-cell">
							<div class="css-table">
								<div class="css-row label-row">
									<div class="css-cell">
										Code <span class="secondary" id="filename"></span>
									</div>
								</div>
								<div class="css-row">
									<div class="css-cell">
										<div class="relative-container">
											<div class="editor-layout">
												<div class="ui-layout-center pane">
													<pre id="editor" >// ██████╗ ██████╗  ██████╗ ██╗  ██╗
// ██╔══██╗██╔══██╗██╔═══██╗██║  ██║
// ██║  ██║██████╔╝██║   ██║███████║
// ██║  ██║██╔═══╝ ██║   ██║██╔══██║
// ██████╔╝██║     ╚██████╔╝██║  ██║ ████████╗
// ╚═════╝ ╚═╝      ╚═════╝ ╚═╝  ╚═╝ ╚═══════╝
//                                 
// Start an Xdebug session in order to begin</pre>
												</div>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
						<div class="css-cell context-cell">
							<div class="status-layout">
								<div class="ui-layout-center pane">
									<div id="context_main" class="status-panel css-table">
										<div class="css-row label-row">
											<div class="css-cell" data-hint="Displays the current context (when a session is active); double click an item to alter its value">
												Context
											</div>
										</div>
										<div class="css-row">
											<div class="css-cell">
												<div class="scroller">
													<div id="context">
													</div>
												</div>
											</div>
										</div>
									</div>
								</div>
								<div class="ui-layout-south pane">
									<div id="stack_main" class="css-table status-panel">
										<div class="css-row label-row ui-resizable-handle ui-resizable-n">
											<div class="css-cell splitter-horizontal" data-hint="Displays the items currently in the stack (when a session is active); click an item to go to jump to the corresponding file and line">
												Stack
											</div>
										</div>
										<div class="css-row">
											<div class="css-cell">
												<div class="scroller">
													<div id="stack">
													</div>
												</div>
											</div>
										</div>
									</div>	
								</div>	
							</div>
						</div>
					</div>
				</div>
			</div>
			<div class="ui-layout-south">
				<div class="css-table no-max-height">
					<div class="css-row label-row">
						<div class="css-cell">
							Console
						</div>
					</div>
				</div>
				<div id="console">
					<div class="history">
					</div>
					<div class="css-table input-table no-max-height">
						<div class="css-row">
							<div class="css-cell active-line">
								<i class="fa fa-fw fa-chevron-right"></i>
							</div>
							<div class="css-cell">
								<textarea placeholder="Enter PHP code here" class="input"></textarea>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<div class="modal-overlay" style="display: none;">
			<div class="modal modal-hidden">
				<div class="modal-title-bar">
					<div class="modal-title"></div>
					<div class="modal-exit"><span class="fa fa-close"></span></div>
				</div>
				<div class="modal-content">
				</div>
			</div>
		</div>
	</body>
</html>