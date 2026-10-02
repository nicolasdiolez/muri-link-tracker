/**
 * ErrorBoundary component — Catches React rendering errors and shows a recovery UI.
 *
 * @package
 * @since   1.0.0
 */

import { Component } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Error Boundary that prevents the entire admin UI from crashing.
 *
 * Must be a class component because React error boundaries require
 * getDerivedStateFromError / componentDidCatch lifecycle methods.
 */
class ErrorBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state = { hasError: false, error: null };
	}

	static getDerivedStateFromError( error ) {
		return { hasError: true, error };
	}

	componentDidCatch( error, errorInfo ) {
		// eslint-disable-next-line no-console
		console.error( '[MuriLinkTracker] UI Error:', error, errorInfo );
	}

	handleReload = () => {
		this.setState( { hasError: false, error: null } );
	};

	render() {
		if ( this.state.hasError ) {
			return (
				<div className="mltr-error-boundary">
					<Notice status="error" isDismissible={ false }>
						<p>
							<strong>
								{ __(
									'Something went wrong.',
									'muri-link-tracker'
								) }
							</strong>
						</p>
						<p>
							{ __(
								'An unexpected error occurred in the plugin interface. Please try reloading.',
								'muri-link-tracker'
							) }
						</p>
						{ this.state.error && (
							<details style={ { marginTop: '8px' } }>
								<summary>
									{ __(
										'Error details',
										'muri-link-tracker'
									) }
								</summary>
								<pre
									style={ {
										fontSize: '12px',
										whiteSpace: 'pre-wrap',
										marginTop: '4px',
									} }
								>
									{ this.state.error.toString() }
								</pre>
							</details>
						) }
					</Notice>
					<div style={ { marginTop: '12px' } }>
						<Button variant="primary" onClick={ this.handleReload }>
							{ __( 'Try Again', 'muri-link-tracker' ) }
						</Button>
					</div>
				</div>
			);
		}

		return this.props.children;
	}
}

export default ErrorBoundary;
