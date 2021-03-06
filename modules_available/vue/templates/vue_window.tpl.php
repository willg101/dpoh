<div data-role="window" data-window-title="<?php echo $title ?>" data-window-id="<?php echo $id ?>" data-minimize-icon="<?php echo $icon ?>" class="not-loaded">
	<div class="css-table">
		<div class="css-row label-row">
			<div class="css-cell">
				<?php echo $title ?>
				<span class="secondary"><?php echo $secondary ?></span>
				<div class="window-buttons">
					<span class="btn no-margin-top sm minimize"><i class="fa fa-fw fa-minus"></i></span>
					<span class="btn no-margin-top sm maximize"><i class="fa fa-fw fa-expand"></i></span>
					<span class="btn no-margin-top sm unmaximize"><i class="fa fa-fw fa-compress"></i></span>
				</div>
			</div>
		</div>
		<div class="css-row">
			<div class="css-cell">
				<?php echo $content ?>
			</div>
		</div>
	</div>
</div>
