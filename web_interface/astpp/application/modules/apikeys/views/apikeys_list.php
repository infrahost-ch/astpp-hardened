<?php extend('master.php') ?>
<?php startblock('extra_head') ?>
<script type="text/javascript">
	$(document).ready(function () {
		$('#apikey_copy').click(function () {
			var field = document.getElementById('apikey_new');
			field.select();
			field.setSelectionRange(0, 200);
			if (navigator.clipboard) {
				navigator.clipboard.writeText(field.value);
			} else {
				document.execCommand('copy');
			}
			$(this).text('<?php echo addslashes(gettext('Copied')); ?>');
		});
	});
</script>
<?php endblock() ?>
<?php startblock('page-title') ?>
<?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?>
<?php endblock() ?>
<?php startblock('content') ?>
<?php $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }; ?>
<section class="slice color-three">
	<div class="w-section inverse p-0">
		<div class="col-12">
			<div class="card p-4 mb-4">
				<h3 class="text-dark p-0 mb-3"><?php echo $e(gettext('API Access')); ?></h3>
				<p class="mb-2"><?php echo $e(gettext('With an API key, your own systems can manage customers, SIP devices and packages and read balances, usage and call records.')); ?></p>
				<p class="mb-2"><?php echo $e(gettext('API address')); ?>: <code><?php echo $e($api_base); ?></code> &middot;
					<a href="<?php echo base_url(); ?>apikeys/apikeys_docs/"><?php echo $e(gettext('API documentation')); ?></a></p>
				<p class="mb-0 text-muted"><?php echo $e(gettext('A key only works from the IP addresses allowed for it. Your current IP address:')); ?> <code><?php echo $e($client_ip); ?></code></p>
			</div>

			<?php if ($new_key !== '') { ?>
			<div class="card p-4 mb-4 border border-success">
				<h3 class="text-success p-0 mb-3"><?php echo $e(gettext('New API key created')); ?></h3>
				<p class="mb-2"><strong><?php echo $e(gettext('Copy the key now. It is shown only once and cannot be displayed again.')); ?></strong></p>
				<div class="input-group mb-2">
					<input type="text" class="form-control" id="apikey_new" readonly value="<?php echo $e($new_key); ?>" style="font-family: monospace;">
					<div class="input-group-append">
						<button class="btn btn-success" type="button" id="apikey_copy"><?php echo $e(gettext('Copy')); ?></button>
					</div>
				</div>
				<p class="mb-0 text-muted"><?php echo $e(gettext('Send it in every request as HTTP header: Authorization: Bearer <key>')); ?></p>
			</div>
			<?php } ?>

			<div class="card p-4 mb-4">
				<div class="mb-3">
					<a class="btn btn-success" href="<?php echo base_url(); ?>apikeys/apikeys_add/"><i class="fa fa-plus-circle fa-lg mr-2"></i><?php echo $e(gettext('New API Key')); ?></a>
				</div>
				<?php if (empty($keys)) { ?>
					<p class="mb-0"><?php echo $e(gettext('There are no API keys yet.')); ?></p>
				<?php } else { ?>
				<div class="table-responsive">
					<table class="table table-striped table-bordered mb-0">
						<thead>
							<tr>
								<th><?php echo $e(gettext('Name')); ?></th>
								<th><?php echo $e(gettext('Key')); ?></th>
								<?php if ($is_admin) { ?><th><?php echo $e(gettext('Account')); ?></th><?php } ?>
								<th><?php echo $e(gettext('Allowed IP addresses')); ?></th>
								<th><?php echo $e(gettext('Status')); ?></th>
								<th><?php echo $e(gettext('Created')); ?></th>
								<th><?php echo $e(gettext('Last used')); ?></th>
								<th><?php echo $e(gettext('Action')); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($keys as $k) { ?>
							<tr>
								<td><?php echo $e($k['name']); ?></td>
								<td><code>astpp_<?php echo $e($k['key_id']); ?>_&hellip;</code></td>
								<?php if ($is_admin) { ?>
								<td><?php
									$roles = array('-1' => gettext('Admin'), '1' => gettext('Reseller'), '0' => gettext('Customer'));
									$owner = trim($k['company_name']) !== '' ? $k['company_name'] : trim($k['first_name'] . ' ' . $k['last_name']);
									echo $e($owner . ' (' . $k['number'] . ', ' . (isset($roles[(string) $k['type']]) ? $roles[(string) $k['type']] : '?') . ')');
								?></td>
								<?php } ?>
								<td><?php echo implode('<br>', array_map($e, explode(',', $k['allowed_ips']))); ?></td>
								<td><?php echo (string) $k['status'] === '0' ? '<span class="badge badge-success">' . $e(gettext('Active')) . '</span>' : '<span class="badge badge-secondary">' . $e(gettext('Disabled')) . '</span>'; ?></td>
								<td><?php echo $e($this->common->convert_GMT_to('', '', $k['created_date'])); ?></td>
								<td><?php echo empty($k['last_used_date']) ? '&ndash;' : $e($this->common->convert_GMT_to('', '', $k['last_used_date'])) . '<br><small>' . $e($k['last_used_ip']) . '</small>'; ?></td>
								<td class="text-nowrap">
									<a class="btn btn-royelblue btn-sm" href="<?php echo base_url(); ?>apikeys/apikeys_edit/<?php echo (int) $k['id']; ?>/" title="<?php echo $e(gettext('Edit')); ?>"><i class="fa fa-pencil-square-o fa-fw"></i></a>
									<form method="post" action="<?php echo base_url(); ?>apikeys/apikeys_delete/<?php echo (int) $k['id']; ?>/" class="d-inline" onsubmit="return confirm('<?php echo addslashes(gettext('Delete this API key? Systems using it lose access immediately.')); ?>');">
										<input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
										<button type="submit" class="btn btn-royelblue btn-sm" title="<?php echo $e(gettext('Delete')); ?>"><i class="fa fa-trash fa-fw"></i></button>
									</form>
								</td>
							</tr>
						<?php } ?>
						</tbody>
					</table>
				</div>
				<?php } ?>
			</div>
		</div>
	</div>
</section>
<?php endblock() ?>
<?php end_extend() ?>
