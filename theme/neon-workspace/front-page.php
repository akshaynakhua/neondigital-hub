<?php
/**
 * Public homepage. Plans and prices are read live from the plans in wp-admin, and nothing here writes
 * data. Product images are real screens of the app, captured from a local copy with fictional sample
 * data, so no customer data can ever appear here.
 */
defined( 'ABSPATH' ) || exit;

$nw_plans = class_exists( 'NWC_Signup' ) ? NWC_Signup::public_plans() : array();
$nw_start = nw_site_start_url( $nw_plans );
$nw_trial = null;
foreach ( $nw_plans as $nw_p ) {
	if ( $nw_p['is_trial'] ) {
		$nw_trial = $nw_p;
		break;
	}
}
$nw_trial_once = class_exists( 'NWC' ) ? ! empty( NWC::settings()['trial_once_per_user'] ) : false;
$nw_cta        = $nw_trial ? 'Start your ' . (int) $nw_trial['trial_days'] . '-day free trial' : 'See plans';
$nw_img        = function ( $name ) {
	return nw_asset( 'img/product/' . $name . '.webp' );
};
$nw_icon       = function ( $paths ) {
	return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
};
$nw_check      = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8.500l3.200 3L13 4.500"/></svg>';

$nw_tabs = array(
	array( 'dashboard', 'Dashboard', 'Your whole day on one screen', 'Calendar, tasks, bookings, new leads, active projects and team workload, arranged the way you choose.', 'The Workspace dashboard with today’s overview, calendars, projects, bookings and leads' ),
	array( 'calendar', 'Calendar', 'Every schedule, one calendar', 'Personal, business and project calendars side by side, with bookings and due dates already on them.', 'The Workspace month calendar with personal, business, booking and project entries' ),
	array( 'tasks', 'Tasks', 'Know who is doing what', 'Filter by status or person, set priorities and due dates, and see what needs attention first.', 'The Workspace task list with assignees, due dates, priorities and statuses' ),
	array( 'bookings', 'Bookings', 'Appointments that stay organised', 'See every booking with its customer, service and status, alongside services and booking pages.', 'The Workspace bookings list showing date, customer, service and status' ),
	array( 'crm', 'Customers', 'One record for every customer', 'Keep customers and leads together, with contact details, source and history you can search.', 'The Workspace customer list with names, phone numbers, email and source' ),
	array( 'invoices', 'Invoices', 'See what is sent and what is paid', 'Invoices and quotations in one list, with totals, balances and clear statuses.', 'The Workspace invoice list with totals, balances and paid, sent and draft statuses' ),
	array( 'finance', 'Finance', 'A clear record of your money', 'Income, expenses, net and outstanding amounts, with payments by method and who still owes you.', 'The Workspace accounts and finance screen with income, expenses, net and outstanding amounts' ),
	array( 'forms', 'Forms', 'Collect details without the paperwork', 'Publish forms as a link or embed them on your site, and see entries and views for each one.', 'The Workspace custom forms list with published and draft forms and their entries' ),
	array( 'reports', 'Reports', 'Answers for any dates you pick', 'Choose a date range to see invoicing, money received, bookings, new leads and tasks by status.', 'The Workspace reports screen with invoiced and received totals, bookings, leads and tasks' ),
);

$nw_problems = array(
	array( 'Bookings arrive through calls, chats and messages.', 'Give customers a booking page that shows your services and open times, and every booking lands in your calendar.' ),
	array( 'Customer details are spread across phones and spreadsheets.', 'Customers and leads live in one searchable place, connected to their bookings and invoices.' ),
	array( 'Work is tracked in chats and memory.', 'Projects hold their own tasks, milestones, members, files and discussion, so everyone sees what is next.' ),
	array( 'You lose track of who has paid.', 'Create quotes and invoices, record payments, and see who owes you without building a spreadsheet.' ),
	array( 'Your numbers only make sense at the end of the month.', 'Finance and Reports show income, expenses and activity for any dates you pick.' ),
);

$nw_rows = array(
	array( 'project', 'Projects &amp; Tasks', 'Plan the work and keep it moving', 'Each project gets one home for everything around the work, so nothing depends on someone remembering it.', array( 'Tasks with assignees, priorities and due dates', 'Milestones, members, files and discussion in each project', 'A project calendar and a full history of changes' ), 'The Bloom Cafe rebrand project in Workspace, with tabs for tasks, calendar, milestones, members, files, discussion and history' ),
	array( 'bookings', 'Bookings', 'Take bookings without the back and forth', 'Set up what you offer and when you are open, then share a page customers can book from themselves.', array( 'Services with their own durations, prices and capacity', 'Public booking pages for your business', 'Staff schedules, business hours and booking reports' ), 'The bookings screen in Workspace with tabs for services, booking pages, business setup and reports' ),
	array( 'invoices', 'Invoices &amp; Finance', 'From quote to paid, in one place', 'Turn accepted work into invoices, then keep a simple record of money in and out.', array( 'Quotations that convert into invoices', 'Optional GST, with your branding, bank details and UPI QR', 'PDF invoices, payment records and reminders' ), 'The invoice list in Workspace with paid, partially paid, sent and draft invoices' ),
);

$nw_modules = array(
	array( 'Projects', 'Group work with milestones, members, files and discussion.', '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>' ),
	array( 'Tasks', 'Assign, prioritise and track every task to done.', '<path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/>' ),
	array( 'Calendar', 'Personal, business and project calendars together.', '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>' ),
	array( 'Customers &amp; Leads', 'Your customer records and new enquiries in one list.', '<circle cx="9" cy="8" r="3.500"/><path d="M3 20c0-3.300 2.700-6 6-6s6 2.700 6 6"/><path d="M16 11a3 3 0 0 0 0-6M21 20c0-2.600-1.600-4.800-4-5.600"/>' ),
	array( 'Bookings', 'Services, booking pages, staff schedules and reports.', '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>' ),
	array( 'Invoices &amp; Quotes', 'Branded quotes and invoices, with optional GST.', '<path d="M6 3h9l4 4v14H6z"/><path d="M15 3v4h4M9 12h7M9 16h7"/>' ),
	array( 'Accounts &amp; Finance', 'Income, expenses, payments and who owes you.', '<circle cx="12" cy="12" r="9"/><path d="M9 8h6M9 11h6M10 8c3 0 3 6-1 6l5 3"/>' ),
	array( 'Custom Forms', 'Share a link or embed a form on your own site.', '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h4"/>' ),
	array( 'Registration Forms', 'Sign people up for events, with capacity and a waitlist.', '<path d="M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4z"/><path d="M14 6v12"/>' ),
	array( 'Reports', 'Invoices, bookings, leads and tasks for any dates.', '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>' ),
	array( 'Daily Planner', 'Priorities, tasks, events and notes for today.', '<path d="M4 6h16M4 12h10M4 18h7"/><path d="M17 15l2 2 3-4"/>' ),
	array( 'Meetings', 'Schedule meetings with agendas and action items.', '<rect x="3" y="6" width="13" height="12" rx="2"/><path d="M16 10l5-3v10l-5-3"/>' ),
	array( 'Team', 'Invite people and give each one the right role.', '<circle cx="12" cy="8" r="3.500"/><path d="M5 20c0-3.900 3.100-7 7-7s7 3.100 7 7"/>' ),
	array( 'Files &amp; Google Drive', 'Keep files with your work and connect Google Drive.', '<path d="M8 3h8l6 10-4 7H6l-4-7z"/><path d="M8 3l6 10h8M2 13h12l4 7"/>' ),
	array( 'Customer Portal', 'A private link where customers see what is theirs.', '<path d="M10 14a4 4 0 0 0 6 0l3-3a4 4 0 0 0-6-6l-1 1"/><path d="M14 10a4 4 0 0 0-6 0l-3 3a4 4 0 0 0 6 6l1-1"/>' ),
	array( 'Notes &amp; Goals', 'Capture ideas and keep your goals in view.', '<path d="M5 3h14v12l-6 6H5z"/><path d="M19 15h-6v6"/>' ),
);

$nw_solutions = array(
	array( 'Business owners', 'See bookings, customers, tasks and money from one dashboard, instead of checking four different tools.', array( 'Dashboard', 'Bookings', 'Customers', 'Finance' ) ),
	array( 'Freelancers', 'Run client work on your own: send the quote, plan the project, raise the invoice and follow up.', array( 'Projects', 'Quotes', 'Invoices', 'Daily Planner' ) ),
	array( 'Agencies', 'Keep each client’s projects, tasks and files in order, and share progress through a customer portal.', array( 'Projects', 'Team', 'Files', 'Customer Portal' ) ),
	array( 'Studios', 'Take bookings for sessions and classes, register people for workshops, and manage staff schedules.', array( 'Bookings', 'Registration Forms', 'Calendar', 'Team' ) ),
	array( 'Teams', 'Give everyone a role and a clear list of work, and see how the workload is shared.', array( 'Tasks', 'Team', 'Meetings', 'Calendar' ) ),
);

$nw_faq = array(
	array( 'What is Neon Workspace?', 'An online workspace for running a business. It brings projects, tasks, calendars, bookings, customers, invoices, finance, forms and reports together, so you can manage them from one place.' ),
);
if ( $nw_trial ) {
	$nw_faq[] = array( 'Is there a free trial?', 'Yes. The Free Trial lasts ' . (int) $nw_trial['trial_days'] . ' days. You choose it, create your account and set up your workspace. The sign-up form does not ask for card details.' . ( $nw_trial_once ? ' Each person can start one free trial.' : '' ) );
}
$nw_faq = array_merge(
	$nw_faq,
	array(
		array( 'What happens when a trial or subscription ends?', 'You get reminders first. If it is not renewed, access is locked after any grace period. Your records are not removed for that reason, and access returns when you renew.' ),
		array( 'Can my customers book online?', 'Yes. You add your services and hours, then share a public booking page. Bookings appear in your Workspace and on your business calendar. Customers can also manage a booking through their own link.' ),
		array( 'Does it handle GST invoices?', 'GST is optional. Turn it on for your business and invoices show tax columns, with CGST and SGST or IGST worked out from the place of supply. Invoices can carry your branding, bank details and an uploaded UPI QR, and can be downloaded as PDFs.' ),
		array( 'Can my team use it too?', 'Yes. Invite people to your workspace and give each person a role: Owner, Admin, Manager, Team Member or Restricted Viewer. Roles decide what each person can see and change.' ),
		array( 'Does it work on my phone?', 'Yes. Workspace adapts to phones and tablets in the browser, with a bottom menu for your most used modules. It can also be installed to your home screen as an app.' ),
		array( 'Can I collect information from my website?', 'Yes. Custom Forms can be shared as a link or embedded on your site, and can add new enquiries to your leads. Registration Forms handle event sign-ups, with capacity limits and a waitlist.' ),
		array( 'I already work with NeonDigital. How do I connect?', 'NeonDigital sends each agency client a personal invitation link. Open it to set your password and connect, and your agency projects, approvals and deliverables appear inside your workspace.' ),
	)
);

nw_head(
	'Neon Workspace: everything your business needs, in one workspace',
	array(
		'index'       => true,
		'landing'     => true,
		'description' => 'Neon Workspace brings projects, tasks, calendars, bookings, customers, invoices, finance, forms and reports together in one workspace for your business.',
	)
);
?>
<script>document.documentElement.classList.add('js');</script>
</head>
<body class="nw-site">
<?php nw_site_header( '', $nw_start ); ?>

<main id="main">
	<section class="hero">
		<div class="wrap hero-copy">
			<span class="eyebrow">Neon Workspace by NeonDigital</span>
			<h1>Everything Your Business Needs. <span>All in One Workspace.</span></h1>
			<p class="lede">Projects, tasks, calendars, bookings, customers, invoices and finance, finally in the same place. Spend less time switching tools and more time running your business.</p>
			<div class="cta-row">
				<a class="btn btn-accent btn-lg" href="<?php echo esc_url( $nw_start ); ?>"><?php echo esc_html( $nw_cta ); ?></a>
				<a class="btn btn-line btn-lg" href="#product">See the product</a>
			</div>
			<?php if ( $nw_trial ) : ?><p class="hero-note">No card details at sign-up.</p><?php endif; ?>
		</div>
		<div class="wrap hero-visual" data-reveal>
			<div class="device-browser hero-browser">
				<div class="browser-bar" aria-hidden="true"><i></i><i></i><i></i><span>workspace.neondigital.in</span></div>
				<img src="<?php echo esc_url( $nw_img( 'dashboard' ) ); ?>" width="1680" height="1050" alt="The Neon Workspace dashboard with today’s overview, calendars, projects, bookings and leads" fetchpriority="high">
			</div>
			<div class="device-phone hero-phone">
				<img src="<?php echo esc_url( $nw_img( 'phone' ) ); ?>" width="560" height="1212" alt="The Neon Workspace dashboard on a phone">
			</div>
		</div>
	</section>

	<section class="section section-alt">
		<div class="wrap anchor" id="product">
			<header class="sec-head" data-reveal>
				<span class="eyebrow">Product</span>
				<h2>See it the way you will use it.</h2>
				<p>These are real screens from the Workspace app, shown with fictional sample data.</p>
			</header>
			<div class="tabs" data-tabs data-reveal>
				<div class="tab-list" role="tablist" aria-label="Workspace modules">
					<?php foreach ( $nw_tabs as $i => $t ) : ?>
						<button class="tab" type="button" role="tab" id="tab-<?php echo esc_attr( $t[0] ); ?>" aria-controls="panel-<?php echo esc_attr( $t[0] ); ?>" aria-selected="<?php echo 0 === $i ? 'true' : 'false'; ?>"<?php echo 0 === $i ? '' : ' tabindex="-1"'; ?>><?php echo esc_html( $t[1] ); ?></button>
					<?php endforeach; ?>
				</div>
				<?php foreach ( $nw_tabs as $i => $t ) : ?>
					<div class="tab-panel" role="tabpanel" id="panel-<?php echo esc_attr( $t[0] ); ?>" aria-labelledby="tab-<?php echo esc_attr( $t[0] ); ?>"<?php echo 0 === $i ? '' : ' hidden'; ?>>
						<div class="tab-text"><h3><?php echo esc_html( $t[2] ); ?></h3><p><?php echo esc_html( $t[3] ); ?></p></div>
						<div class="device-browser">
							<div class="browser-bar" aria-hidden="true"><i></i><i></i><i></i><span>workspace.neondigital.in</span></div>
							<picture>
								<source media="(max-width: 760px)" srcset="<?php echo esc_url( $nw_img( $t[0] . '-main' ) ); ?>" width="1208" height="900">
								<img src="<?php echo esc_url( $nw_img( $t[0] ) ); ?>" width="1680" height="1050" alt="<?php echo esc_attr( $t[4] ); ?>" loading="lazy">
							</picture>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<section class="section">
		<div class="wrap split anchor" id="problems">
			<header class="sec-head split-head" data-reveal>
				<span class="eyebrow">Why Workspace</span>
				<h2>Running a business shouldn’t take five apps.</h2>
				<p>When your work is scattered, things get missed. Workspace puts the everyday parts of your business in one connected place.</p>
				<a class="text-link" href="<?php echo esc_url( $nw_start ); ?>">Get started <span aria-hidden="true">→</span></a>
			</header>
			<ol class="problems">
				<?php foreach ( $nw_problems as $p ) : ?>
					<li class="problem" data-reveal>
						<p class="problem-q"><?php echo esc_html( $p[0] ); ?></p>
						<p class="problem-a"><span class="tick"><?php echo $nw_check; // phpcs:ignore ?></span><span><?php echo esc_html( $p[1] ); ?></span></p>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
	</section>

	<section class="section section-alt">
		<div class="wrap anchor" id="features">
			<header class="sec-head" data-reveal>
				<span class="eyebrow">Features</span>
				<h2>Every part of your business, connected.</h2>
				<p>Start with what you need today. Everything works together as your business grows.</p>
			</header>
			<div class="rows">
				<?php foreach ( $nw_rows as $i => $r ) : ?>
					<article class="row<?php echo $i % 2 ? ' row-flip' : ''; ?>" data-reveal>
						<div class="row-text">
							<span class="row-kicker"><?php echo wp_kses_post( $r[1] ); ?></span>
							<h3><?php echo esc_html( $r[2] ); ?></h3>
							<p><?php echo esc_html( $r[3] ); ?></p>
							<ul class="checks">
								<?php foreach ( $r[4] as $li ) : ?><li><span class="tick"><?php echo $nw_check; // phpcs:ignore ?></span><span><?php echo esc_html( $li ); ?></span></li><?php endforeach; ?>
							</ul>
						</div>
						<div class="device-browser row-shot">
							<div class="browser-bar" aria-hidden="true"><i></i><i></i><i></i></div>
							<img src="<?php echo esc_url( $nw_img( $r[0] . '-row' ) ); ?>" width="1208" height="640" alt="<?php echo esc_attr( $r[5] ); ?>" loading="lazy">
						</div>
					</article>
				<?php endforeach; ?>
			</div>
			<h3 class="modules-title" data-reveal>And everything else in your workspace</h3>
			<div class="modules">
				<?php foreach ( $nw_modules as $m ) : ?>
					<article class="module" data-reveal>
						<span class="module-icon"><?php echo $nw_icon( $m[2] ); // phpcs:ignore ?></span>
						<h4><?php echo wp_kses_post( $m[0] ); ?></h4>
						<p><?php echo esc_html( $m[1] ); ?></p>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<section class="section">
		<div class="wrap anchor" id="solutions">
			<header class="sec-head" data-reveal>
				<span class="eyebrow">Solutions</span>
				<h2>Built around the way you work.</h2>
				<p>The same workspace, shaped by the modules you lean on most.</p>
			</header>
			<div class="solutions">
				<?php foreach ( $nw_solutions as $s ) : ?>
					<article class="solution" data-reveal>
						<h3><?php echo esc_html( $s[0] ); ?></h3>
						<p><?php echo esc_html( $s[1] ); ?></p>
						<ul class="chips"><?php foreach ( $s[2] as $c ) : ?><li><?php echo esc_html( $c ); ?></li><?php endforeach; ?></ul>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<section class="section section-alt">
		<div class="wrap anchor" id="how">
			<header class="sec-head" data-reveal>
				<span class="eyebrow">How it works</span>
				<h2>Up and running in three steps.</h2>
			</header>
			<ol class="steps">
				<li class="step" data-reveal><span class="step-n">1</span><h3>Choose your plan</h3><p><?php echo $nw_trial ? 'Start the free trial or pick a plan, then create your account.' : 'Pick a plan, then create your account.'; ?></p></li>
				<li class="step" data-reveal><span class="step-n">2</span><h3>Set up your workspace</h3><p>Add your business details, services and customers, and invite your team.</p></li>
				<li class="step" data-reveal><span class="step-n">3</span><h3>Run your day from one place</h3><p>Open your dashboard to see what is booked, what is due and what needs you next.</p></li>
			</ol>
		</div>
	</section>

	<section class="section">
		<div class="wrap anchor" id="pricing">
			<header class="sec-head" data-reveal>
				<span class="eyebrow">Pricing</span>
				<h2>Plans for where your business is today.</h2>
				<p><?php echo $nw_trial ? 'Try it free for ' . (int) $nw_trial['trial_days'] . ' days, then choose the plan that fits.' : 'Choose the plan that fits.'; ?></p>
			</header>
			<?php if ( nw_plans_have_annual( $nw_plans ) ) { nw_billing_switch(); } ?>
			<?php if ( $nw_plans ) : ?>
				<?php nw_plan_cards( $nw_plans, true ); ?>
			<?php else : ?>
				<p class="plans-empty">Plans will be published here soon.</p>
			<?php endif; ?>
			<p class="plans-note"><a class="text-link" href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">Compare all plans <span aria-hidden="true">→</span></a></p>
		</div>
	</section>

	<section class="section section-alt">
		<div class="wrap split anchor" id="faq">
			<header class="sec-head split-head" data-reveal>
				<span class="eyebrow">FAQ</span>
				<h2>Questions, answered.</h2>
				<p>Something else on your mind? <a href="https://neondigital.in/contact" rel="noopener">Contact NeonDigital</a>.</p>
			</header>
			<div class="faq" data-faq data-reveal>
				<?php foreach ( $nw_faq as $q ) : ?>
					<details class="faq-item">
						<summary><?php echo esc_html( $q[0] ); ?><span class="faq-sign" aria-hidden="true"></span></summary>
						<div class="faq-a"><p><?php echo esc_html( $q[1] ); ?></p></div>
					</details>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<section class="section cta-section">
		<div class="wrap">
			<div class="cta-card" data-reveal>
				<h2>Bring your whole business into one workspace.</h2>
				<p>Set up your workspace today and see your projects, customers, bookings and invoices in one place.</p>
				<div class="cta-row">
					<a class="btn btn-accent btn-lg" href="<?php echo esc_url( $nw_start ); ?>"><?php echo esc_html( $nw_cta ); ?></a>
					<a class="btn btn-line btn-lg" href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">View pricing</a>
				</div>
			</div>
		</div>
	</section>
</main>

<?php nw_site_footer( '', $nw_start ); ?>
<script src="<?php echo esc_url( nw_asset( 'js/landing.js' ) ); ?>"></script>
</body>
</html>
