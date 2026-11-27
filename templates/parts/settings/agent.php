<?php
/** Synced from _shared/agent-auth — do not edit app-side. */

declare(strict_types=1);

/**
 * "AI access (MCP)" settings section — pack partial.
 *
 * Expects $agentAccess from AgentSettingsPayload::payload(). Renders
 * nothing when the user may neither mint nor see tokens (nav-gate, not a
 * disabled state). All IDs/classes use the app prefix (mn).
 *
 * @var \OCP\IL10N $l
 * @var array $agentAccess
 */

$agentAccess = is_array($agentAccess ?? null) ? $agentAccess : [];
if (($agentAccess['visible'] ?? false) !== true) {
	return;
}

$mn_state = [
	'appId' => (string)($agentAccess['appId'] ?? ''),
	'agentHeader' => (string)($agentAccess['agentHeader'] ?? ''),
	'ncUrl' => (string)($agentAccess['ncUrl'] ?? ''),
	'isAdmin' => (bool)($agentAccess['isAdmin'] ?? false),
	'canMint' => (bool)($agentAccess['canMint'] ?? false),
	'selfMintEnabled' => (bool)($agentAccess['selfMintEnabled'] ?? false),
	'tokens' => $agentAccess['tokens'] ?? [],
	'presets' => $agentAccess['presets'] ?? ['read' => [], 'act' => []],
	'allScopes' => $agentAccess['allScopes'] ?? [],
	'scopeLabels' => $agentAccess['scopeLabels'] ?? [],
];
$mn_tokens = is_array($mn_state['tokens']) ? $mn_state['tokens'] : [];
?>
<section class="mn-section mn-agent" id="mn-agent-access" aria-labelledby="mn-agent-h">
	<h2 class="mn-section-title" id="mn-agent-h"><?php p($l->t('AI access (MCP)')); ?></h2>
	<p class="mn-section-intro">
		<?php p($l->t('Connect an AI assistant to this app with a scoped token. The token works only for this app — never for your files, mail, or other apps.')); ?>
	</p>
	<div id="mn-agent-live" class="mn-visually-hidden" aria-live="polite"></div>

	<?php if ($mn_tokens === []): ?>
	<div class="mn-agent-empty" id="mn-agent-empty">
		<p class="mn-agent-empty__text"><?php p($l->t('No AI access yet.')); ?></p>
		<?php if ($mn_state['canMint']): ?>
		<button type="button" class="mn-btn mn-btn--primary" id="mn-agent-connect"><?php p($l->t('Connect your AI')); ?></button>
		<?php endif; ?>
	</div>
	<?php else: ?>
	<div class="mn-table-wrap mn-agent-tablewrap" tabindex="0" role="region" aria-labelledby="mn-agent-h">
		<table class="mn-table mn-table--cards mn-agent-table" id="mn-agent-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Label')); ?></th>
					<th scope="col"><?php p($l->t('Scopes')); ?></th>
					<th scope="col"><?php p($l->t('Created')); ?></th>
					<th scope="col"><?php p($l->t('Expires')); ?></th>
					<th scope="col"><?php p($l->t('Last used')); ?></th>
					<th scope="col"><span class="mn-visually-hidden"><?php p($l->t('Actions')); ?></span></th>
				</tr>
			</thead>
			<tbody id="mn-agent-tbody">
				<?php foreach ($mn_tokens as $mn_tok): ?>
				<tr data-token-id="<?php p((string)($mn_tok['id'] ?? '')); ?>" data-token-label="<?php p((string)($mn_tok['label'] ?? '')); ?>"<?php if (($mn_tok['state'] ?? '') !== 'active'): ?> class="mn-agent-row--inactive"<?php endif; ?>>
					<td data-cell="<?php p($l->t('Label')); ?>">
						<span class="mn-agent-label"><?php p((string)($mn_tok['label'] ?? '')); ?></span>
						<?php if (($mn_tok['state'] ?? '') === 'revoked'): ?>
							<span class="mn-badge mn-badge--neutral"><?php p($l->t('Revoked')); ?></span>
						<?php elseif (($mn_tok['state'] ?? '') === 'expired'): ?>
							<span class="mn-badge mn-badge--neutral"><?php p($l->t('Expired')); ?></span>
						<?php else: ?>
							<span class="mn-badge mn-badge--info"><?php p($l->t('Active')); ?></span>
						<?php endif; ?>
						<?php if ($mn_state['isAdmin'] && ($mn_tok['userId'] ?? '') !== ''): ?>
							<span class="mn-agent-owner"><?php p((string)$mn_tok['userId']); ?></span>
						<?php endif; ?>
					</td>
					<td data-cell="<?php p($l->t('Scopes')); ?>">
						<span class="mn-agent-scopes">
						<?php foreach (($mn_tok['scopes'] ?? []) as $mn_scope): ?>
							<span class="mn-badge mn-badge--neutral mn-agent-scope" title="<?php p((string)$mn_scope); ?>"><?php p((string)($mn_state['scopeLabels'][$mn_scope] ?? $mn_scope)); ?></span>
						<?php endforeach; ?>
						</span>
					</td>
					<td data-cell="<?php p($l->t('Created')); ?>" data-ts="<?php p((string)($mn_tok['createdAt'] ?? '')); ?>"></td>
					<td data-cell="<?php p($l->t('Expires')); ?>" data-ts="<?php p((string)($mn_tok['expiresAt'] ?? '')); ?>"></td>
					<td data-cell="<?php p($l->t('Last used')); ?>"<?php if (($mn_tok['lastUsedAt'] ?? null) === null): ?> class="mn-agent-never"<?php endif; ?> data-ts="<?php p((string)($mn_tok['lastUsedAt'] ?? '')); ?>"><?php if (($mn_tok['lastUsedAt'] ?? null) === null): ?><?php p($l->t('never')); ?><?php endif; ?></td>
					<td class="mn-row-actions">
						<?php if (($mn_tok['state'] ?? '') === 'active'): ?>
						<button type="button" class="mn-btn mn-btn--ghost mn-agent-rotate" data-token-id="<?php p((string)$mn_tok['id']); ?>"><?php p($l->t('Rotate')); ?></button>
						<button type="button" class="mn-btn mn-btn--danger mn-agent-revoke" data-token-id="<?php p((string)$mn_tok['id']); ?>" data-token-label="<?php p((string)($mn_tok['label'] ?? '')); ?>"><?php p($l->t('Revoke')); ?></button>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ($mn_state['canMint']): ?>
		<div class="mn-agent-actions">
			<button type="button" class="mn-btn mn-btn--primary" id="mn-agent-connect"><?php p($l->t('Connect your AI')); ?></button>
		</div>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php if ($mn_state['isAdmin']): ?>
	<div class="mn-form-row mn-agent-selfmint">
		<input type="hidden" name="agent_self_mint" value="0">
		<label class="mn-checkbox-label" for="mn-agent-selfmint-cb">
			<input type="checkbox" id="mn-agent-selfmint-cb" <?php if ($mn_state['selfMintEnabled']): ?>checked<?php endif; ?>>
			<?php p($l->t('Let app users create their own AI tokens')); ?>
		</label>
		<p class="mn-form-row__hint" id="mn-agent-selfmint-hint"><?php p($l->t('When off, only app administrators can connect AI assistants.')); ?></p>
	</div>
	<?php endif; ?>

	<dialog id="mn-agent-mint-dialog" class="mn-dialog mn-agent-mint-dialog" aria-labelledby="mn-agent-mint-h">
		<div class="mn-dialog__body">
			<h3 id="mn-agent-mint-h"><?php p($l->t('Connect your AI')); ?></h3>
			<form id="mn-agent-mint-form" class="mn-form-stack" novalidate>
				<div class="mn-form-row">
					<label for="mn-agent-label"><?php p($l->t('Label')); ?></label>
					<input type="text" id="mn-agent-label" name="label" maxlength="190" required autocomplete="off" placeholder="<?php p($l->t('Claude on my laptop')); ?>">
					<p class="mn-field-error" id="mn-agent-label-error" hidden></p>
				</div>
				<fieldset class="mn-form-row mn-fieldset">
					<legend><?php p($l->t('What may the AI do?')); ?></legend>
					<div class="mn-choice-cards">
						<label class="mn-choice-card">
							<input type="radio" name="preset" value="read" checked>
							<span class="mn-choice-card__copy">
								<span class="mn-choice-card__title"><?php p($l->t('Read only')); ?></span>
								<span class="mn-choice-card__hint"><?php p($l->t('see your data, never change it')); ?></span>
							</span>
						</label>
						<label class="mn-choice-card">
							<input type="radio" name="preset" value="act">
							<span class="mn-choice-card__copy">
								<span class="mn-choice-card__title"><?php p($l->t('Read + act on my behalf')); ?></span>
								<span class="mn-choice-card__hint"><?php p($l->t('read and make changes — payments and legal stays with you')); ?></span>
							</span>
						</label>
						<label class="mn-choice-card">
							<input type="radio" name="preset" value="custom">
							<span class="mn-choice-card__copy">
								<span class="mn-choice-card__title"><?php p($l->t('Choose exactly')); ?></span>
								<span class="mn-choice-card__hint"><?php p($l->t('pick each permission yourself')); ?></span>
							</span>
						</label>
					</div>
				</fieldset>
				<fieldset class="mn-form-row mn-fieldset" id="mn-agent-custom-wrap" hidden>
					<legend><?php p($l->t('Permissions')); ?></legend>
					<div class="mn-agent-chips" id="mn-agent-chips"></div>
					<p class="mn-field-error" id="mn-agent-scopes-error" hidden></p>
				</fieldset>
				<p class="mn-form-row__hint"><?php p($l->t('Valid for 1 year. The token only reaches this app.')); ?></p>
			</form>
		</div>
		<footer class="mn-dialog__footer">
			<div class="mn-actions">
				<button type="button" class="mn-btn mn-btn--secondary" id="mn-agent-mint-cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="submit" form="mn-agent-mint-form" class="mn-btn mn-btn--primary" id="mn-agent-mint-submit"><?php p($l->t('Create token')); ?></button>
			</div>
		</footer>
	</dialog>

	<dialog id="mn-agent-secret-dialog" class="mn-dialog mn-agent-secret-dialog" aria-labelledby="mn-agent-secret-h">
		<div class="mn-dialog__body">
			<h3 id="mn-agent-secret-h"><?php p($l->t('Your AI is connected')); ?></h3>
			<p class="mn-form-row__hint"><?php p($l->t('Copy this config into your AI client now — the token is shown only once.')); ?></p>
			<div class="mn-form-row">
				<label for="mn-agent-secret"><?php p($l->t('Token')); ?></label>
				<div class="mn-input-group">
					<input type="password" id="mn-agent-secret" readonly autocomplete="off" spellcheck="false">
					<button type="button" class="mn-btn mn-btn--ghost" id="mn-agent-secret-reveal" aria-pressed="false"><?php p($l->t('Show')); ?></button>
					<button type="button" class="mn-btn mn-btn--ghost" id="mn-agent-secret-copy"><?php p($l->t('Copy')); ?></button>
				</div>
			</div>
			<div class="mn-form-row">
				<label for="mn-agent-config"><?php p($l->t('Client config')); ?></label>
				<textarea id="mn-agent-config" class="mn-agent-config" readonly rows="10" spellcheck="false"></textarea>
			</div>
		</div>
		<footer class="mn-dialog__footer">
			<div class="mn-actions">
				<button type="button" class="mn-btn mn-btn--secondary" id="mn-agent-secret-close"><?php p($l->t('Done')); ?></button>
				<button type="button" class="mn-btn mn-btn--primary" id="mn-agent-config-copy"><?php p($l->t('Copy config')); ?></button>
			</div>
		</footer>
	</dialog>

	<dialog id="mn-agent-revoke-dialog" class="mn-dialog" aria-labelledby="mn-agent-revoke-h">
		<div class="mn-dialog__body">
			<h3 id="mn-agent-revoke-h"><?php p($l->t('Revoke token?')); ?></h3>
			<p id="mn-agent-revoke-text"></p>
		</div>
		<footer class="mn-dialog__footer">
			<div class="mn-actions">
				<button type="button" class="mn-btn mn-btn--secondary" id="mn-agent-revoke-cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="button" class="mn-btn mn-btn--danger" id="mn-agent-revoke-confirm"><?php p($l->t('Revoke')); ?></button>
			</div>
		</footer>
	</dialog>

	<script type="application/json" id="mn-agent-state"><?php
		print_unescaped(json_encode($mn_state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
	?></script>
</section>
