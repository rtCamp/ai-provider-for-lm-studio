/**
 * LM Studio Settings Models Script
 *
 * This handles the loading of available models from the LM Studio local server
 * and displays reasoning configuration dynamically in the WordPress admin panel.
 */

import './style.scss';
import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';
import { __, _n, sprintf } from '@wordpress/i18n';

// Type definitions to help TypeScript understand the data structures.
// These match the structure defined by our PHP settings class.
interface LMStudioSettingsGlobal {
	ajaxUrl?: string;
	capabilitiesAjaxUrl?: string;
	selectedModel?: string;
	selectedReasoning?: string;
}

declare global {
	interface Window {
		// WordPress exposes this global object to pass saved database settings to our JS.
		ConnectorForLMStudioSettings?: LMStudioSettingsGlobal;
	}
}

// Represents reasoning capabilities (e.g. standard vs deep thinking) configured on models.
interface ReasoningCapability {
	allowed_options: string[];
	default: string;
}

// Maps model identifiers to their specific parameters/capabilities.
interface ModelCapabilities {
	reasoning: ReasoningCapability | null;
	vision?: boolean;
	trained_for_tool_use?: boolean;
}

// Structure representing a single model retrieved from LM Studio.
interface LMStudioModel {
	id?: string;
	name?: string;
	key?: string;
}

// Wrapper for standard WordPress API responses.
interface AjaxResponse<T> {
	success: boolean;
	data: T | string;
}

// Cache to store the retrieved reasoning configurations of each model.
let modelCapabilitiesMap: Record<string, ModelCapabilities> = {};

// Safely retrieve the settings passed from WordPress PHP.
const settings = window.ConnectorForLMStudioSettings || {};

/**
 * Helper function to extract Model ID safely from response.
 *
 * Some models use the "id" field while others use "name".
 * We gracefully fall back to ensure we always have an identifier.
 *
 * @param {LMStudioModel} model Model object.
 */
const getModelId = ( model: LMStudioModel ): string => model?.id || model?.name || '';

/**
 * Get the currently selected Model ID in the select box.
 */
const getSelectedModelId = (): string => {
	const select = document.getElementById(
		'connector_for_lmstudio_settings-model',
	) as HTMLSelectElement | null;
	return select?.value || '';
};

/**
 * Renders beautiful capability indicator badges (Vision, Tool Calling, Reasoning)
 * next to the selected model dropdown.
 */
const renderCapabilitiesBadges = (): void => {
	const select = document.getElementById(
		'connector_for_lmstudio_settings-model',
	) as HTMLSelectElement | null;
	const container = document.getElementById( 'lmstudio-models-container' );

	if ( ! select || ! container ) {
		return;
	}

	// Find or create badges container
	let badgesContainer = document.getElementById( 'lmstudio-capabilities-badges-container' );
	if ( ! badgesContainer ) {
		badgesContainer = document.createElement( 'div' );
		badgesContainer.id = 'lmstudio-capabilities-badges-container';
		badgesContainer.className = 'lmstudio-capabilities-badges-container';

		// Insert directly inside models container
		container.appendChild( badgesContainer );
	}

	// Clear previous badges
	badgesContainer.innerHTML = '';

	const modelId = select.value;
	if ( ! modelId ) {
		badgesContainer.style.display = 'none';
		return;
	}

	const capabilities = modelCapabilitiesMap[ modelId ];
	if ( ! capabilities ) {
		badgesContainer.style.display = 'none';
		return;
	}

	// Create vision badge if true
	if ( capabilities.vision ) {
		const visionBadge = document.createElement( 'span' );
		visionBadge.className = 'lmstudio-cap-badge lmstudio-cap-vision';
		visionBadge.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
			<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
			<circle cx="12" cy="12" r="3"></circle>
		</svg> ${ __( 'Vision', 'connector-for-lmstudio' ) }`;
		badgesContainer.appendChild( visionBadge );
	}

	// Create tool use badge if true
	if ( capabilities.trained_for_tool_use ) {
		const toolBadge = document.createElement( 'span' );
		toolBadge.className = 'lmstudio-cap-badge lmstudio-cap-tools';
		toolBadge.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
			<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>
		</svg> ${ __( 'Tool Calling', 'connector-for-lmstudio' ) }`;
		badgesContainer.appendChild( toolBadge );
	}

	// Create reasoning support badge if reasoning capability exists
	const reasoning = capabilities.reasoning || null;
	if (
		reasoning &&
		Array.isArray( reasoning.allowed_options ) &&
		reasoning.allowed_options.length > 0
	) {
		const reasoningBadge = document.createElement( 'span' );
		reasoningBadge.className = 'lmstudio-cap-badge lmstudio-cap-reasoning';
		reasoningBadge.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
			<path d="M9.5 2A2.5 2.5 0 0 1 12 4.5v15a2.5 2.5 0 0 1-4.96.44 2.5 2.5 0 0 1 0-3.88 2.5 2.5 0 0 1 0-3.88 2.5 2.5 0 0 1 0-3.88A2.5 2.5 0 0 1 9.5 2z"></path>
			<path d="M14.5 2A2.5 2.5 0 0 0 12 4.5v15a2.5 2.5 0 0 0 4.96.44 2.5 2.5 0 0 0 0-3.88 2.5 2.5 0 0 0 0-3.88 2.5 2.5 0 0 0 0-3.88A2.5 2.5 0 0 0 14.5 2z"></path>
		</svg> ${ __( 'Reasoning', 'connector-for-lmstudio' ) }`;
		badgesContainer.appendChild( reasoningBadge );
	}

	// Show container if it has badges
	if ( badgesContainer.children.length > 0 ) {
		badgesContainer.style.display = 'flex';
	} else {
		badgesContainer.style.display = 'none';
	}
};

/**
 * Renders reasoning radio buttons for the currently selected model.
 *
 * @param {string} savedReasoning Previously saved reasoning value.
 */
const renderReasoning = ( savedReasoning: string ): void => {
	const container = document.getElementById(
		'lmstudio-reasoning-container',
	);
	const fieldset = document.getElementById(
		'lmstudio-reasoning-fieldset',
	);

	// Stop if the reasoning UI containers are missing from the page layout.
	if ( ! container || ! fieldset ) {
		return;
	}

	// Remove any previously rendered radio labels (keep the <legend>).
	Array.from( fieldset.querySelectorAll( 'label' ) ).forEach( ( el ) => el.remove() );

	const modelId = getSelectedModelId();
	const capabilities = modelId ? modelCapabilitiesMap[ modelId ] : null;
	const reasoning = capabilities?.reasoning || null;

	// If this model does not support reasoning features, hide the UI section entirely.
	if (
		! reasoning ||
		! Array.isArray( reasoning.allowed_options ) ||
		reasoning.allowed_options.length === 0
	) {
		container.classList.remove( 'lmstudio-visible' );
		container.style.display = 'none';
		return;
	}

	// Figure out which reasoning mode should be pre-selected on load.
	const options = reasoning.allowed_options;
	const modelDefault = reasoning.default || options[ 0 ] || '';
	const effectiveValue =
		savedReasoning && options.includes( savedReasoning )
			? savedReasoning
			: modelDefault;

	const hiddenInput = document.getElementById(
		'connector_for_lmstudio_settings-reasoning',
	) as HTMLInputElement | null;

	// Sync the hidden form input so it saves correctly when the user submits the settings page.
	if ( hiddenInput ) {
		hiddenInput.value = effectiveValue;
	}

	// Dynamically build and insert radio inputs for each available reasoning option.
	options.forEach( ( option ) => {
		const label = document.createElement( 'label' );
		label.className = 'lmstudio-reasoning-card';
		if ( option === effectiveValue ) {
			label.classList.add( 'lmstudio-checked' );
		}

		const radio = document.createElement( 'input' );
		radio.type = 'radio';
		radio.name = 'connector_for_lmstudio_settings[reasoning]';
		radio.id = 'lmstudio-reasoning-' + option;
		radio.value = option;
		radio.checked = option === effectiveValue;
		label.htmlFor = radio.id;

		// Update the hidden form field whenever a different radio option is checked.
		radio.addEventListener( 'change', () => {
			if ( hiddenInput ) {
				hiddenInput.value = option;
			}
			Array.from( fieldset.querySelectorAll( 'label' ) ).forEach( ( el ) => el.classList.remove( 'lmstudio-checked' ) );
			label.classList.add( 'lmstudio-checked' );
		} );

		// Capitalize the first letter for a friendlier UI display label.
		const capitalised =
			option.charAt( 0 ).toUpperCase() + option.slice( 1 );

		// Assemble premium custom components inside the radio card
		label.appendChild( radio );

		const headerDiv = document.createElement( 'div' );
		headerDiv.className = 'lmstudio-card-header';

		const titleSpan = document.createElement( 'span' );
		titleSpan.className = 'lmstudio-card-title';
		titleSpan.textContent = capitalised;

		const indicatorDiv = document.createElement( 'div' );
		indicatorDiv.className = 'lmstudio-radio-indicator';

		headerDiv.appendChild( titleSpan );
		headerDiv.appendChild( indicatorDiv );
		label.appendChild( headerDiv );

		const descDiv = document.createElement( 'div' );
		descDiv.className = 'lmstudio-card-desc';
		if ( option === 'on' ) {
			descDiv.textContent = __( 'Enable full deep thinking reasoning capability for high-quality problem solving.', 'connector-for-lmstudio' );
		} else if ( option === 'off' ) {
			descDiv.textContent = __( 'Disable reasoning mode for standard fast responses without extended thinking cycles.', 'connector-for-lmstudio' );
		} else {
			/* translators: %s: reasoning option mode label */
			descDiv.textContent = sprintf( __( 'Activate "%s" reasoning capability mode.', 'connector-for-lmstudio' ), capitalised );
		}
		label.appendChild( descDiv );

		fieldset.appendChild( label );
	} );

	// Reveal the reasoning field section since options exist.
	container.style.display = 'block';
	requestAnimationFrame( () => {
		container.classList.add( 'lmstudio-visible' );
	} );
};

/**
 * Renders option tags inside the select element.
 *
 * @param {LMStudioModel[]} models        List of models.
 * @param {string}          selectedModel Currently selected model.
 */
const renderModels = (
	models: LMStudioModel[],
	selectedModel: string,
): void => {
	const select = document.getElementById(
		'connector_for_lmstudio_settings-model',
	) as HTMLSelectElement | null;
	const status = document.getElementById( 'lmstudio-model-status' );

	if ( ! select || ! status ) {
		return;
	}

	// Clear out any old dropdown options.
	select.innerHTML = '';

	// Add the placeholder default option allowing fallback to general client model.
	const defaultOption = document.createElement( 'option' );
	defaultOption.value = '';
	defaultOption.textContent = __( 'Use model selected by AI Client', 'connector-for-lmstudio' );
	select.appendChild( defaultOption );

	let hasSelectedModel = false;

	// Handle the edge case where no active models are available from LM Studio.
	if ( models.length === 0 ) {
		status.innerHTML = `<span class="lmstudio-loader-badge lmstudio-error-badge">
			<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
				<circle cx="12" cy="12" r="10"></circle>
				<line x1="12" y1="8" x2="12" y2="12"></line>
				<line x1="12" y1="16" x2="12.01" y2="16"></line>
			</svg>
			${ __( 'No models found', 'connector-for-lmstudio' ) }
		</span>`;

		// If a model was saved previously, keep displaying it so the user does not lose state.
		if ( selectedModel ) {
			const selectedOnlyOption = document.createElement( 'option' );
			selectedOnlyOption.value = selectedModel;
			/* translators: %s: Saved model identifier */
			selectedOnlyOption.textContent = sprintf( __( '%s (saved)', 'connector-for-lmstudio' ), selectedModel );
			selectedOnlyOption.selected = true;
			select.appendChild( selectedOnlyOption );
		}

		return;
	}

	// Populate the dropdown with all models retrieved from the local LM Studio server.
	models.forEach( ( model ) => {
		const modelId = getModelId( model );
		if ( ! modelId ) {
			return;
		}

		const option = document.createElement( 'option' );
		option.value = modelId;
		option.textContent = modelId;

		// Pre-select the option if it matches the user's previously saved setting.
		if ( selectedModel && modelId === selectedModel ) {
			option.selected = true;
			hasSelectedModel = true;
		}

		select.appendChild( option );
	} );

	// If the previously saved model is no longer active in LM Studio, display it with a "saved" label.
	if ( selectedModel && ! hasSelectedModel ) {
		const missingOption = document.createElement( 'option' );
		missingOption.value = selectedModel;
		/* translators: %s: Saved model identifier */
		missingOption.textContent = sprintf( __( '%s (saved)', 'connector-for-lmstudio' ), selectedModel );
		missingOption.selected = true;
		select.appendChild( missingOption );
	}

	// Render the model loaded count beautifully
	const countText = sprintf(
		/* translators: %d: number of models loaded */
		_n( '%d model loaded', '%d models loaded', models.length, 'connector-for-lmstudio' ),
		models.length,
	);
	status.innerHTML = `<span class="lmstudio-loader-badge lmstudio-success-badge">
		<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
			<polyline points="20 6 9 17 4 12"></polyline>
		</svg>
		${ countText }
	</span>`;

	// Re-enable input selector once load completes
	select.disabled = false;

	// Refresh the reasoning options dynamic field when the user switches models.
	select.addEventListener( 'change', () => {
		renderReasoning( '' );
		renderCapabilitiesBadges();
	} );
};

// Render user-facing error state when connection or fetch fails.
const renderError = ( message: string ): void => {
	const status = document.getElementById( 'lmstudio-model-status' );
	if ( ! status ) {
		return;
	}

	status.innerHTML = `<span class="lmstudio-loader-badge lmstudio-error-badge" title="${ message }">
		<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
			<circle cx="12" cy="12" r="10"></circle>
			<line x1="12" y1="8" x2="12" y2="12"></line>
			<line x1="12" y1="16" x2="12.01" y2="16"></line>
		</svg>
		${ __( 'Connection failed', 'connector-for-lmstudio' ) }
	</span>`;
};

// Asynchronously load the active models from the local LM Studio server via WordPress REST API.
const loadModels = ( ajaxUrl: string, selectedModel: string ): void => {
	const status = document.getElementById( 'lmstudio-model-status' );
	const select = document.getElementById(
		'connector_for_lmstudio_settings-model',
	) as HTMLSelectElement | null;

	if ( ! status ) {
		return;
	}

	// Disable dropdown while fetching and render spinner badge
	if ( select ) {
		select.disabled = true;
	}
	status.innerHTML = `<span class="lmstudio-loader-badge">
		<div class="lmstudio-spinner"></div>
		${ __( 'Fetching models…', 'connector-for-lmstudio' ) }
	</span>`;

	apiFetch<AjaxResponse<LMStudioModel[]>>( {
		url: ajaxUrl,
	} )
		.then( ( payload ) => {
			if ( ! payload || ! payload.success ) {
				throw new Error(
					payload && typeof payload.data === 'string'
						? payload.data
						: __( 'Failed to load models.', 'connector-for-lmstudio' ),
				);
			}

			const models = Array.isArray( payload.data ) ? payload.data : [];
			renderModels( models, selectedModel );
		} )
		.catch( ( error: Error ) => {
			if ( select ) {
				select.disabled = false;
			}
			renderError(
				error?.message || __( 'Failed to load models.', 'connector-for-lmstudio' ),
			);
		} );
};

/**
 * Fetches per-model reasoning capabilities and, once available, renders the
 * reasoning radio buttons for the currently selected model.
 *
 * @param {string} capabilitiesAjaxUrl Capabilities Ajax URL.
 * @param {string} savedReasoning      Currently saved reasoning mode.
 */
const loadCapabilities = (
	capabilitiesAjaxUrl: string,
	savedReasoning: string,
): void => {
	apiFetch<AjaxResponse<Record<string, ModelCapabilities>>>( {
		url: capabilitiesAjaxUrl,
	} )
		.then( ( payload ) => {
			if (
				! payload ||
				! payload.success ||
				typeof payload.data !== 'object'
			) {
				return;
			}
			// Map and cache capabilities in local state, then draw radio controls.
			modelCapabilitiesMap = payload.data || {};
			renderReasoning( savedReasoning );
			renderCapabilitiesBadges();
		} )
		.catch( () => {
			// Fail silently – reasoning UI simply stays hidden if endpoints fail.
		} );
};

// Main initializer method that triggers all necessary fetches.
const init = (): void => {
	if ( ! settings.ajaxUrl ) {
		return;
	}

	// Load active model list first.
	loadModels( settings.ajaxUrl, settings.selectedModel || '' );

	// Fetch dynamic model features if capabilities endpoint is defined.
	if ( settings.capabilitiesAjaxUrl ) {
		loadCapabilities(
			settings.capabilitiesAjaxUrl,
			settings.selectedReasoning || '',
		);
	}
};

// Bootstrap initialization using standard WordPress domReady callback.
domReady( init );
