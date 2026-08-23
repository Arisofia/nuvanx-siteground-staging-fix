<?php
/**
 * Medical solutions hub markup.
 *
 * WordPress provides routing and metadata only. Visible structure for
 * /soluciones-medicas/ is versioned here; clinical solution groups live in
 * inc/data/nvx-soluciones-medicas-groups.json.
 *
 * @package nuvanx-medical
 */

defined( 'ABSPATH' ) || exit;

$valuation_url = home_url( '/madrid/valoracion/' );
$contour_arch  = "Contour Architecture™\u{2122}";

// Start standard content wrapper
echo '<div class="entry-content nvx-page__content">';

$solution_groups = array();
$decoded         = function_exists( 'nvx_theme_load_json_catalog' )
	? nvx_theme_load_json_catalog( 'nvx-soluciones-medicas-groups.json' )
	: array();
foreach ( $decoded as $group ) {
	if ( ! is_array( $group ) ) {
		continue;
	}
	$solutions = array();
	foreach ( (array) ( $group['solutions'] ?? array() ) as $solution ) {
		if ( ! is_array( $solution ) ) {
			continue;
		}
		if ( ( $solution['protocol'] ?? '' ) === 'contour_architecture' ) {
			$solution['protocol'] = $contour_arch;
		}
		$solutions[] = $solution;
	}
	$group['solutions'] = $solutions;
	$solution_groups[]  = $group;
}

$method_steps = array(
	array(
		'index' => '01',
		'title' => 'Escuchar el motivo de consulta',
		'body'  => 'Definimos qué cambio buscas y qué resultado consideras proporcionado.',
	),
	array(
		'index' => '02',
		'title' => 'Explorar anatomía y tejido',
		'body'  => 'Revisamos estructura, grasa, laxitud, superficie, fototipo y antecedentes.',
	),
	array(
		'index' => '03',
		'title' => 'Separar causas y límites',
		'body'  => 'Diferenciamos qué componente puede tratarse y qué requiere otra alternativa.',
	),
	array(
		'index' => '04',
		'title' => 'Documentar el plan',
		'body'  => 'Explicamos técnica, fases, cuidados, seguimiento y presupuesto individualizado.',
	),
);
?>
<div class="nvx-brand-page nvx-solutions-page" id="nvx-solutions-page">
	<section class="nvx-brand-hero" aria-labelledby="nvx-solutions-title">
		<div class="nvx-brand-hero__inner">
			<div class="nvx-brand-hero__copy">
				<p class="nvx-brand-kicker">SOLUCIONES MÉDICAS · NUVANX MADRID</p>
				<h1 id="nvx-solutions-title" class="nvx-brand-hero__title">Soluciones médicas para rostro, piel y contorno corporal.</h1>
				<p class="nvx-brand-hero__lead">La preocupación orienta la consulta. El diagnóstico define el tratamiento. Organizamos las soluciones por anatomía y por causa clínica, no por catálogo de máquinas. Antes de recomendar una tecnología diferenciamos grasa, laxitud, soporte, textura, pigmentación y otros componentes que pueden producir signos similares.</p>
				<div class="nvx-brand-actions">
					<a class="nvx-brand-btn nvx-brand-btn--primary" href="<?php echo esc_url( $valuation_url ); ?>">Solicitar valoración médica</a>
					<a class="nvx-brand-btn nvx-brand-btn--secondary" href="#mapa-soluciones">Explorar soluciones</a>
				</div>
				<p class="nvx-brand-meta">Diagnóstico individual · Indicación proporcionada · Seguimiento médico</p>
			</div>
		</div>
	</section>

	<nav id="mapa-soluciones" class="nvx-solutions-nav" aria-label="Mapa de soluciones médicas">
		<div class="nvx-solutions-shell nvx-solutions-nav__inner">
			<?php foreach ( $solution_groups as $group ) : ?>
				<a href="#<?php echo esc_attr( $group['id'] ); ?>"><span><?php echo esc_html( $group['index'] ); ?></span><?php echo esc_html( $group['eyebrow'] ); ?></a>
			<?php endforeach; ?>
		</div>
	</nav>

	<section class="nvx-solutions-principle" aria-labelledby="nvx-solutions-principle-title">
		<div class="nvx-solutions-shell nvx-solutions-principle__grid">
			<div>
				<p class="nvx-solutions-eyebrow">ANTES DE LA TECNOLOGÍA</p>
				<h2 id="nvx-solutions-principle-title">Una misma preocupación puede tener causas distintas.</h2>
			</div>
			<div class="nvx-solutions-principle__body">
				<p>Dos personas pueden consultar por la misma zona y necesitar planes distintos. La anatomía, la calidad del tejido, los antecedentes y los límites clínicos cambian la indicación.</p>
				<p>La valoración también puede concluir que conviene esperar, derivar o no tratar. Esa decisión forma parte del criterio médico.</p>
			</div>
		</div>
	</section>

	<?php foreach ( $solution_groups as $group ) : ?>
		<section id="<?php echo esc_attr( $group['id'] ); ?>" class="nvx-solutions-group nvx-solutions-group--<?php echo esc_attr( $group['surface'] ); ?>" aria-labelledby="<?php echo esc_attr( $group['id'] ); ?>-title">
			<div class="nvx-solutions-shell">
				<header class="nvx-solutions-group__header">
					<div class="nvx-solutions-group__index"><?php echo esc_html( $group['index'] ); ?></div>
					<div>
						<p class="nvx-solutions-eyebrow"><?php echo esc_html( $group['eyebrow'] ); ?></p>
						<h2 id="<?php echo esc_attr( $group['id'] ); ?>-title"><?php echo esc_html( $group['title'] ); ?></h2>
					</div>
					<p class="nvx-solutions-group__intro"><?php echo esc_html( $group['intro'] ); ?></p>
				</header>
				<div class="nvx-solutions-grid">
					<?php foreach ( (array) ( $group['solutions'] ?? array() ) as $solution ) : ?>
						<article class="nvx-solutions-card">
							<div class="nvx-solutions-card__content">
								<?php if ( ! empty( $solution['protocol'] ) ) : ?>
									<p class="nvx-solutions-card__protocol"><?php echo esc_html( $solution['protocol'] ); ?></p>
								<?php endif; ?>
								<h3><?php echo esc_html( $solution['title'] ); ?></h3>
								<dl>
									<dt>Qué se valora</dt>
									<dd><?php echo esc_html( $solution['question'] ); ?></dd>
									<dt>Límites</dt>
									<dd><?php echo esc_html( $solution['limit'] ); ?></dd>
								</dl>
							</div>
							<a class="nvx-solutions-card__link" href="<?php echo esc_url( home_url( $solution['path'] ) ); ?>" aria-label="<?php echo esc_attr( 'Explorar solución: ' . $solution['title'] ); ?>">Explorar solución <span aria-hidden="true">→</span></a>
						</article>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
	<?php endforeach; ?>

	<section class="nvx-solutions-method" aria-labelledby="nvx-solutions-method-title">
		<div class="nvx-solutions-shell">
			<p class="nvx-solutions-eyebrow">CÓMO SE CONSTRUYE EL PLAN</p>
			<h2 id="nvx-solutions-method-title">De la preocupación visible a una indicación documentada.</h2>
			<ol class="nvx-solutions-method__steps">
				<?php foreach ( $method_steps as $step ) : ?>
					<li>
						<span><?php echo esc_html( $step['index'] ); ?></span>
						<h3><?php echo esc_html( $step['title'] ); ?></h3>
						<p><?php echo esc_html( $step['body'] ); ?></p>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
	</section>

	<section class="nvx-solutions-closure" aria-labelledby="nvx-solutions-closure-title">
		<div class="nvx-solutions-shell nvx-solutions-closure__inner">
			<p class="nvx-solutions-eyebrow">TU PRIMERA VALORACIÓN</p>
			<h2 id="nvx-solutions-closure-title">No necesitas elegir un tratamiento antes de consultar.</h2>
			<p>Cuéntanos qué zona o cambio quieres valorar. El equipo médico estudiará la causa, las alternativas razonables y los límites antes de proponer cualquier procedimiento.</p>
			<div class="nvx-brand-actions">
				<a class="nvx-brand-btn nvx-brand-btn--primary" href="<?php echo esc_url( $valuation_url ); ?>">Solicitar valoración médica</a>
				<a class="nvx-brand-btn nvx-brand-btn--secondary" href="<?php echo esc_url( home_url( '/equipo-medico/' ) ); ?>">Conocer al equipo médico</a>
			</div>
		</div>
	</section>

<?php
// Close standard content wrapper
echo '</div>';
?>
