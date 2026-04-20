<?php
/**
 * Deployment Map — interactive 5-node pipeline visualization.
 *
 * Expected variables from the parent view (app-editor.php):
 *   $app         App model instance
 *   $buildStatus string current build status meta
 *
 * The map renders server-side with initial state; TypeScript in
 * deployment-map.ts subscribes to pollBuildStatus for real-time
 * updates and wires the requirements popovers.
 */

use MustUse\Pub\Admin\DeploymentRequirements;

$checks = DeploymentRequirements::checksForApp( $app );

// Group checks by node for the badge counts.
$nodeChecks = [];
foreach ( $checks as $check ) {
	$n = $check['node'] ?? 0;
	$nodeChecks[ $n ][] = $check;
}

// Derive initial node states from current build status.
$nodeStates = [
	1 => 'complete',  // Authoring is "done" if the publisher has screens
	2 => 'idle',
	3 => 'idle',
	4 => 'waiting',
	5 => 'idle',
];

// Check Node 1: any fail → incomplete
foreach ( $nodeChecks[1] ?? [] as $c ) {
	if ( $c['status'] === 'fail' ) {
		$nodeStates[1] = 'idle';
		break;
	}
}

// Map build status to node states
switch ( $buildStatus ) {
	case 'pending':
		$nodeStates[2] = 'active';
		break;
	case 'projecting':
		$nodeStates[2] = 'active';
		$nodeStates[3] = 'active';
		break;
	case 'projected':
		$nodeStates[2] = 'complete';
		$nodeStates[3] = 'complete';
		$nodeStates[4] = 'waiting';
		break;
	case 'complete':
		$nodeStates[2] = 'complete';
		$nodeStates[3] = 'complete';
		$nodeStates[4] = 'complete';
		$nodeStates[5] = 'complete';
		break;
	case 'failed':
		$nodeStates[2] = 'failed';
		break;
}

$nodes = [
	1 => [ 'icon' => 'edit',      'label' => __( 'WordPress', 'mustuse-apps-pub' ),  'subtitle' => __( 'Author', 'mustuse-apps-pub' ) ],
	2 => [ 'icon' => 'hammer',    'label' => __( 'Assembler', 'mustuse-apps-pub' ),  'subtitle' => __( 'Build', 'mustuse-apps-pub' ) ],
	3 => [ 'icon' => 'cloud',     'label' => __( 'GitHub', 'mustuse-apps-pub' ),     'subtitle' => __( 'Project', 'mustuse-apps-pub' ) ],
	4 => [ 'icon' => 'dashboard', 'label' => __( 'Bifrost', 'mustuse-apps-pub' ),    'subtitle' => __( 'Compile', 'mustuse-apps-pub' ) ],
	5 => [ 'icon' => 'smartphone','label' => __( 'Device', 'mustuse-apps-pub' ),     'subtitle' => __( 'Runtime', 'mustuse-apps-pub' ) ],
];
?>

<div class="mua-dep-map" id="mua-dep-map" data-app-id="<?php echo esc_attr( (string) $app->id() ); ?>">
	<?php foreach ( $nodes as $n => $node ) :
		$state       = $nodeStates[ $n ] ?? 'idle';
		$checksForN  = $nodeChecks[ $n ] ?? [];
		$passCount   = count( array_filter( $checksForN, fn( $c ) => $c['status'] === 'pass' ) );
		$totalCount  = count( $checksForN );
		?>
		<?php if ( $n > 1 ) : ?>
			<div class="mua-dep-map__connector mua-dep-map__connector--<?php echo esc_attr( $state ); ?>"
				data-from="<?php echo esc_attr( (string) ( $n - 1 ) ); ?>"
				data-to="<?php echo esc_attr( (string) $n ); ?>"></div>
		<?php endif; ?>

		<div class="mua-dep-map__node mua-dep-node mua-dep-node--<?php echo esc_attr( $state ); ?>"
			data-node="<?php echo esc_attr( (string) $n ); ?>"
			tabindex="0"
			role="button"
			aria-label="<?php echo esc_attr( $node['label'] ); ?>">

			<span class="mua-dep-node__icon dashicons dashicons-<?php echo esc_attr( $node['icon'] ); ?>" aria-hidden="true"></span>
			<span class="mua-dep-node__label"><?php echo esc_html( $node['label'] ); ?></span>
			<span class="mua-dep-node__subtitle"><?php echo esc_html( $node['subtitle'] ); ?></span>

			<?php if ( $totalCount > 0 ) : ?>
				<span class="mua-dep-node__badge <?php echo $passCount === $totalCount ? 'mua-dep-node__badge--ok' : ''; ?>">
					<?php echo esc_html( $passCount . '/' . $totalCount ); ?>
				</span>
			<?php endif; ?>

			<?php if ( ! empty( $checksForN ) ) : ?>
				<div class="mua-dep-node__popup" hidden>
					<ul class="mua-dep-node__checks">
						<?php foreach ( $checksForN as $check ) : ?>
							<li class="mua-dep-node__check mua-dep-node__check--<?php echo esc_attr( $check['status'] ); ?>">
								<?php echo esc_html( $check['label'] ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>
