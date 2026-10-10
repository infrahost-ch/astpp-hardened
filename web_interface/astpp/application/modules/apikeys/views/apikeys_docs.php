<?php extend('master.php') ?>
<?php startblock('extra_head') ?>
<style>
	.api-docs { line-height: 1.6; max-width: 1100px; }
	.api-docs h1 { font-size: 1.8rem; margin: 0 0 1rem; }
	.api-docs h2 { font-size: 1.4rem; margin: 2.2rem 0 1rem; padding-bottom: .3rem; border-bottom: 1px solid #dee2e6; }
	.api-docs h3 { font-size: 1.1rem; margin: 1.6rem 0 .6rem; }
	.api-docs table { width: 100%; margin: 0 0 1.2rem; border-collapse: collapse; font-size: .92rem; }
	.api-docs th, .api-docs td { border: 1px solid #dee2e6; padding: .4rem .6rem; vertical-align: top; text-align: left; }
	.api-docs thead th { background: #f4f6f8; }
	.api-docs code { background: #f4f6f8; padding: .1rem .3rem; border-radius: 3px; color: #23374d; }
	.api-docs pre { background: #1f2933; color: #e6edf3; padding: .9rem 1rem; border-radius: 4px; overflow-x: auto; font-size: .85rem; }
	.api-docs pre code { background: transparent; color: inherit; padding: 0; }
	.api-docs ul, .api-docs ol { padding-left: 1.4rem; }
</style>
<?php endblock() ?>
<?php startblock('page-title') ?>
<?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?>
<?php endblock() ?>
<?php startblock('content') ?>
<section class="slice color-three">
	<div class="w-section inverse p-0">
		<div class="col-12">
			<div class="card p-4 mb-4">
				<div class="mb-3">
					<a class="btn btn-secondary btn-sm" href="<?php echo base_url(); ?>apikeys/apikeys_list/"><i class="fa fa-key mr-1"></i><?php echo htmlspecialchars(gettext('API Access'), ENT_QUOTES, 'UTF-8'); ?></a>
					<a class="btn btn-secondary btn-sm ml-2" href="<?php echo base_url(); ?>apikeys/apikeys_openapi/"><i class="fa fa-download mr-1"></i>OpenAPI (YAML)</a>
				</div>
				<div class="api-docs">
					<?php /* rendered from modules/apikeys/docs/api.md (part of the code base, trusted) */ echo $docs_html; ?>
				</div>
			</div>
		</div>
	</div>
</section>
<?php endblock() ?>
<?php end_extend() ?>
