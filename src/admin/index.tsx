/**
 * Event Hub admin — RSVP management screen.
 *
 * PHP (Admin_Page) renders <div id="event-hub-rsvps-root"> and inline
 * window.eventHubAdmin data; this React app takes it from there. Lists
 * RSVPs per event with status filter, two-step delete, pagination and a
 * CSV export link (admin-post.php, server-side nonce + capability).
 */
import { createRoot, useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
	SelectControl,
	Spinner,
	Notice,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactElement } from 'react';
import {
	type EventHubAdminData,
	type RsvpRow,
	type RsvpsResponse,
	type StatusFilter,
} from './types';
import './styles/admin.scss';

const PER_PAGE = 50;

type ErrorState = string | null;

/** Read the boot data; fail loud if the page was loaded without it. */
function getBootData(): EventHubAdminData | null {
	return window.eventHubAdmin ?? null;
}

function AdminApp(): ReactElement {
	const boot = useMemo( getBootData, [] );
	const [ eventId, setEventId ] = useState< number >(
		boot?.initialEventId ?? 0
	);
	const [ status, setStatus ] = useState< StatusFilter >( '' );
	const [ page, setPage ] = useState( 1 );
	const [ rows, setRows ] = useState< RsvpRow[] >( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< ErrorState >( null );
	const [ confirmingId, setConfirmingId ] = useState< number | null >( null );
	// Two-step confirm for manual promotion, separate from the delete
	// confirm so the two destructive-ish verbs never render open at once.
	const [ promotingId, setPromotingId ] = useState< number | null >( null );
	// §5B admin compose: collapsed by default; sending is two-step like the
	// destructive verbs (a broadcast cannot be unsent).
	const [ composeOpen, setComposeOpen ] = useState( false );
	const [ emailSubject, setEmailSubject ] = useState( '' );
	const [ emailMessage, setEmailMessage ] = useState( '' );
	const [ emailAudience, setEmailAudience ] = useState<
		'all' | 'going' | 'waitlist'
	>( 'all' );
	const [ composeBusy, setComposeBusy ] = useState( false );
	/** Last send outcome: null = none; ok distinguishes success from failure copy. */
	const [ composeResult, setComposeResult ] = useState< {
		ok: boolean;
		text: string;
	} | null >( null );

	// No boot data: render a terminal error (PHP failed to inline it).
	if ( ! boot ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __(
					'Event Hub admin data is missing — reload the page.',
					'event-hub'
				) }
			</Notice>
		);
	}

	/* eslint-disable react-hooks/rules-of-hooks */
	useEffect( () => {
		if ( ! eventId ) {
			// Index state ("Select an event"): the REST contract rejects
			// eventId=0 with 400, so no request is sent — the prompt renders.
			setRows( [] );
			setTotal( 0 );
			setTotalPages( 1 );
			setError( null );
			setConfirmingId( null );
			setPromotingId( null );
			setLoading( false );
			return;
		}

		let alive = true;
		setLoading( true );
		setError( null );
		setConfirmingId( null );
		setPromotingId( null );

		const params = new URLSearchParams( {
			eventId: String( eventId ),
			page: String( page ),
			perPage: String( PER_PAGE ),
		} );
		if ( status ) {
			params.set( 'status', status );
		}

		fetch( `${ boot.restUrl }event-hub/v1/rsvps?${ params.toString() }`, {
			headers: { 'X-WP-Nonce': boot.nonce },
		} )
			.then( ( response ) => {
				if ( ! response.ok ) {
					throw new Error( String( response.status ) );
				}
				return response.json() as Promise< RsvpsResponse >;
			} )
			.then( ( data ) => {
				if ( ! alive ) {
					return;
				}
				setRows( data.rsvps ?? [] );
				setTotal( data.total ?? 0 );
				setTotalPages( Math.max( data.totalPages ?? 1, 1 ) );
			} )
			.catch( () => {
				if ( alive ) {
					setError(
						__(
							'Could not load RSVPs — please reload the page.',
							'event-hub'
						)
					);
				}
			} )
			.finally( () => {
				if ( alive ) {
					setLoading( false );
				}
			} );

		return () => {
			alive = false;
		};
	}, [ boot, eventId, status, page ] );
	/* eslint-enable react-hooks/rules-of-hooks */

	const deleteRsvp = async ( id: number ): Promise< void > => {
		setError( null );
		try {
			const response = await fetch(
				`${ boot.restUrl }event-hub/v1/rsvps/${ id }`,
				{
					method: 'DELETE',
					headers: { 'X-WP-Nonce': boot.nonce },
				}
			);
			if ( response.status === 404 ) {
				// Already gone — treat as success for the list.
				setRows( ( current ) =>
					current.filter( ( row ) => row.id !== id )
				);
				setTotal( ( current ) => Math.max( 0, current - 1 ) );
				return;
			}
			if ( ! response.ok ) {
				// Prefer the server's message (e.g. eh_busy "The event is
				// busy processing another RSVP") over a generic string; the
				// body is the WP REST error shape { code, message, data }.
				const body = ( await response.json().catch( () => null ) ) as {
					message?: string;
				} | null;
				setError(
					body?.message && '' !== body.message
						? body.message
						: __(
								'Could not delete the RSVP — please try again.',
								'event-hub'
							)
				);
				return;
			}
			setRows( ( current ) =>
				current.filter( ( row ) => row.id !== id )
			);
			setTotal( ( current ) => Math.max( 0, current - 1 ) );
		} catch {
			setError(
				__(
					'Could not delete the RSVP — please try again.',
					'event-hub'
				)
			);
		}
	};

	const setCheckIn = async (
		id: number,
		checkedIn: boolean
	): Promise< void > => {
		setError( null );
		try {
			const response = await fetch(
				`${ boot.restUrl }event-hub/v1/rsvps/${ id }/check-in`,
				{
					method: 'POST',
					headers: {
						'X-WP-Nonce': boot.nonce,
						'Content-Type': 'application/json',
					},
					body: JSON.stringify( { checkedIn } ),
				}
			);
			if ( response.status === 404 ) {
				// Row vanished under us — drop it from the list.
				setRows( ( current ) =>
					current.filter( ( row ) => row.id !== id )
				);
				setTotal( ( current ) => Math.max( 0, current - 1 ) );
				return;
			}
			if ( ! response.ok ) {
				const body = ( await response.json().catch( () => null ) ) as {
					message?: string;
				} | null;
				setError(
					body?.message && '' !== body.message
						? body.message
						: __(
								'Could not save the check-in — please try again.',
								'event-hub'
							)
				);
				return;
			}
			const data = ( await response.json() ) as {
				rsvp: RsvpRow;
			};
			setRows( ( current ) =>
				current.map( ( row ) =>
					row.id === id ? ( data.rsvp ?? row ) : row
				)
			);
		} catch {
			setError(
				__(
					'Could not save the check-in — please try again.',
					'event-hub'
				)
			);
		}
	};

	// Manual waitlist promotion (ROADMAP 5.7): the organizer's per-person
	// "let them in" verb. On success the row's status flips server-side;
	// under a status-filtered list the row no longer matches, so dropping
	// it here keeps the view honest without a refetch. In the All view the
	// row stays visible with its new status (no refetch needed either —
	// the list is not status-sorted beyond the server's ASC id order).
	const promoteRsvp = async ( id: number ): Promise< void > => {
		setError( null );
		try {
			const response = await fetch(
				`${ boot.restUrl }event-hub/v1/rsvps/${ id }/promote`,
				{
					method: 'POST',
					headers: { 'X-WP-Nonce': boot.nonce },
				}
			);
			if ( response.status === 404 ) {
				// Row vanished under us — drop it from the list.
				setRows( ( current ) =>
					current.filter( ( row ) => row.id !== id )
				);
				setTotal( ( current ) => Math.max( 0, current - 1 ) );
				return;
			}
			if ( ! response.ok ) {
				// eh_capacity_full ("No free seat…") and eh_busy carry the
				// server's exact message; prefer it over a generic string.
				const body = ( await response.json().catch( () => null ) ) as {
					message?: string;
				} | null;
				setError(
					body?.message && '' !== body.message
						? body.message
						: __(
								'Could not promote the RSVP — please try again.',
								'event-hub'
							)
				);
				return;
			}
			setConfirmingId( null );
			setPromotingId( null );
			// Status-filtered view: the row left the waitlist set.
			if ( 'waitlist' === status ) {
				setRows( ( current ) =>
					current.filter( ( row ) => row.id !== id )
				);
				setTotal( ( current ) => Math.max( 0, current - 1 ) );
			}
		} catch {
			setError(
				__(
					'Could not promote the RSVP — please try again.',
					'event-hub'
				)
			);
		}
	};

	const eventOptions = [
		{ value: '0', label: __( 'Select an event', 'event-hub' ) },
		...boot.events.map( ( event ) => ( {
			value: String( event.id ),
			label: event.title || `#${ event.id }`,
		} ) ),
	];

	// §5B admin compose: POST /events/{id}/email; the server sends one mail
	// per attendee and returns counts. Subject/message trim to empty = 400
	// with the server's message (the client disables the button too).
	const sendEmail = async (): Promise< void > => {
		setError( null );
		setComposeResult( null );
		setComposeBusy( true );
		try {
			const response = await fetch(
				`${ boot.restUrl }event-hub/v1/events/${ eventId }/email`,
				{
					method: 'POST',
					headers: {
						'X-WP-Nonce': boot.nonce,
						'Content-Type': 'application/json',
					},
					body: JSON.stringify( {
						subject: emailSubject.trim(),
						message: emailMessage.trim(),
						audience: emailAudience,
					} ),
				}
			);
			if ( ! response.ok ) {
				const body = ( await response.json().catch( () => null ) ) as {
					message?: string;
				} | null;
				setComposeResult( {
					ok: false,
					text:
						body?.message && '' !== body.message
							? body.message
							: __(
									'Could not send the email — please try again.',
									'event-hub'
								),
				} );
				return;
			}
			const data = ( await response.json() ) as {
				sent?: number;
				skipped?: number;
			};
			setComposeResult( {
				ok: true,
				text: sprintf(
					/* translators: 1: number sent, 2: number skipped. */
					__(
						'Sent to %1$d attendee(s); %2$d could not be sent.',
						'event-hub'
					),
					data.sent ?? 0,
					data.skipped ?? 0
				),
			} );
			setEmailSubject( '' );
			setEmailMessage( '' );
		} catch {
			setComposeResult( {
				ok: false,
				text: __(
					'Could not send the email — please try again.',
					'event-hub'
				),
			} );
		} finally {
			setComposeBusy( false );
		}
	};

	return (
		<div className="wrap event-hub-admin">
			<h1>{ __( 'Event Hub — RSVPs', 'event-hub' ) }</h1>
			{ error && (
				<Notice
					status="error"
					isDismissible
					onRemove={ () => setError( null ) }
				>
					{ error }
				</Notice>
			) }
			<div className="event-hub-admin__toolbar">
				<SelectControl
					label={ __( 'Event', 'event-hub' ) }
					value={ String( eventId ) }
					onChange={ ( value ) => {
						setEventId( Number.parseInt( value, 10 ) || 0 );
						setPage( 1 );
					} }
					options={ eventOptions }
				/>
				<SelectControl
					label={ __( 'Status', 'event-hub' ) }
					value={ status }
					onChange={ ( value ) => {
						setStatus( value as StatusFilter );
						setPage( 1 );
					} }
					options={ [
						{ value: '', label: __( 'All', 'event-hub' ) },
						{ value: 'going', label: __( 'Going', 'event-hub' ) },
						{
							value: 'waitlist',
							label: __( 'Waitlist', 'event-hub' ),
						},
					] }
				/>
				<Button
					variant="primary"
					href={ boot.exportUrls[ eventId ] ?? undefined }
					disabled={ ! eventId }
				>
					{ __( 'Download CSV', 'event-hub' ) }
				</Button>
			</div>

			{ eventId && (
				<div className="event-hub-admin__compose">
					<Button
						variant="tertiary"
						aria-expanded={ composeOpen }
						onClick={ () => {
							setComposeOpen( ! composeOpen );
							setComposeResult( null );
						} }
					>
						{ composeOpen
							? __( 'Hide email form', 'event-hub' )
							: __( 'Email attendees', 'event-hub' ) }
					</Button>
					{ composeOpen && (
						<div className="event-hub-admin__compose-form">
							<TextControl
								label={ __( 'Subject', 'event-hub' ) }
								value={ emailSubject }
								onChange={ setEmailSubject }
								maxLength={ 200 }
							/>
							<TextareaControl
								label={ __( 'Message', 'event-hub' ) }
								value={ emailMessage }
								onChange={ setEmailMessage }
								help={ __(
									'Plain text only. The event date, event page link and site signature are added automatically.',
									'event-hub'
								) }
								rows={ 6 }
							/>
							<SelectControl
								label={ __( 'Send to', 'event-hub' ) }
								value={ emailAudience }
								onChange={ ( value ) =>
									setEmailAudience(
										(
											[
												'going',
												'waitlist',
												'all',
											] as const
										 ).includes(
											value as typeof emailAudience
										)
											? ( value as typeof emailAudience )
											: 'all'
									)
								}
								options={ [
									{
										value: 'all',
										label: __( 'Everyone', 'event-hub' ),
									},
									{
										value: 'going',
										label: __( 'Going only', 'event-hub' ),
									},
									{
										value: 'waitlist',
										label: __(
											'Waitlist only',
											'event-hub'
										),
									},
								] }
							/>
							{ composeResult && (
								<Notice
									status={
										composeResult.ok ? 'success' : 'error'
									}
									isDismissible
									onRemove={ () => setComposeResult( null ) }
								>
									{ composeResult.text }
								</Notice>
							) }
							<div className="event-hub-admin__compose-actions">
								<Button
									variant="primary"
									disabled={
										composeBusy ||
										'' === emailSubject.trim() ||
										'' === emailMessage.trim()
									}
									onClick={ () => sendEmail() }
								>
									{ composeBusy
										? __( 'Sending…', 'event-hub' )
										: __( 'Send', 'event-hub' ) }
								</Button>
								<Button
									variant="tertiary"
									disabled={ composeBusy }
									onClick={ () => {
										setEmailSubject( '' );
										setEmailMessage( '' );
										setComposeResult( null );
									} }
								>
									{ __( 'Clear', 'event-hub' ) }
								</Button>
							</div>
						</div>
					) }
				</div>
			) }

			{ loading ? (
				<Spinner />
			) : (
				<table className="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col">{ __( 'Name', 'event-hub' ) }</th>
							<th scope="col">{ __( 'Email', 'event-hub' ) }</th>
							<th scope="col">{ __( 'Status', 'event-hub' ) }</th>
							<th scope="col">{ __( 'Seats', 'event-hub' ) }</th>
							<th scope="col">
								{ __( 'Checked in', 'event-hub' ) }
							</th>
							<th scope="col">{ __( 'Date', 'event-hub' ) }</th>
							<th scope="col">
								<span className="screen-reader-text">
									{ __( 'Actions', 'event-hub' ) }
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ ! eventId && (
							<tr>
								<td colSpan={ 7 }>
									{ __(
										'Select an event to see its RSVPs.',
										'event-hub'
									) }
								</td>
							</tr>
						) }
						{ eventId && rows.length === 0 && (
							<tr>
								<td colSpan={ 7 }>
									{ __( 'No RSVPs yet.', 'event-hub' ) }
								</td>
							</tr>
						) }
						{ eventId &&
							rows.map( ( row ) => (
								<tr key={ row.id }>
									<td>
										{ row.name }
										{ row.phone && (
											<span className="event-hub-admin__phone">
												{ ' · ' }
												{ row.phone }
											</span>
										) }
										{ row.note && (
											<span className="event-hub-admin__note">
												<br />
												{ row.note }
											</span>
										) }
									</td>
									<td>{ row.email }</td>
									<td>{ row.status }</td>
									<td>{ row.guests }</td>
									<td>
										{ row.checkedInAt
											? new Date(
													row.checkedInAt
												).toLocaleString()
											: __( '—', 'event-hub' ) }
									</td>
									<td>
										{ new Date(
											row.createdAt
										).toLocaleString() }
									</td>
									<td>
										{ 'waitlist' === row.status &&
											( promotingId === row.id ? (
												<span className="event-hub-admin__confirm">
													<Button
														variant="primary"
														onClick={ () =>
															promoteRsvp(
																row.id
															)
														}
													>
														{ __(
															'Confirm promote',
															'event-hub'
														) }
													</Button>
													<Button
														variant="tertiary"
														onClick={ () =>
															setPromotingId(
																null
															)
														}
													>
														{ __(
															'Cancel',
															'event-hub'
														) }
													</Button>
												</span>
											) : (
												<Button
													variant="tertiary"
													onClick={ () => {
														setConfirmingId( null );
														setPromotingId(
															row.id
														);
													} }
												>
													{ __(
														'Promote',
														'event-hub'
													) }
												</Button>
											) ) }
										{ 'waitlist' !== row.status && (
											<Button
												variant="tertiary"
												onClick={ () =>
													setCheckIn(
														row.id,
														! row.checkedInAt
													)
												}
											>
												{ row.checkedInAt
													? __(
															'Undo check-in',
															'event-hub'
														)
													: __(
															'Check in',
															'event-hub'
														) }
											</Button>
										) }
										{ confirmingId === row.id ? (
											<span className="event-hub-admin__confirm">
												<Button
													isDestructive
													variant="primary"
													onClick={ () =>
														deleteRsvp( row.id )
													}
												>
													{ __(
														'Confirm delete',
														'event-hub'
													) }
												</Button>
												<Button
													variant="tertiary"
													onClick={ () =>
														setConfirmingId( null )
													}
												>
													{ __(
														'Cancel',
														'event-hub'
													) }
												</Button>
											</span>
										) : (
											<Button
												isDestructive
												variant="tertiary"
												onClick={ () => {
													setPromotingId( null );
													setConfirmingId( row.id );
												} }
											>
												{ __( 'Delete', 'event-hub' ) }
											</Button>
										) }
									</td>
								</tr>
							) ) }
					</tbody>
				</table>
			) }

			{ eventId && totalPages > 1 && (
				<div className="event-hub-admin__pagination">
					<Button
						variant="secondary"
						disabled={ page <= 1 }
						onClick={ () => setPage( ( p ) => p - 1 ) }
					>
						{ __( 'Previous', 'event-hub' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: current page, 2: total pages, 3: total RSVPs. */
							__( 'Page %1$d of %2$d (%3$d total)', 'event-hub' ),
							page,
							totalPages,
							total
						) }
					</span>
					<Button
						variant="secondary"
						disabled={ page >= totalPages }
						onClick={ () => setPage( ( p ) => p + 1 ) }
					>
						{ __( 'Next', 'event-hub' ) }
					</Button>
				</div>
			) }
		</div>
	);
}

const root = document.getElementById( 'event-hub-rsvps-root' );
if ( root ) {
	createRoot( root ).render( <AdminApp /> );
}
