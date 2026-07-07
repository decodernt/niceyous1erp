<div class="card mb-5 mb-xl-10">
	<div class="card-body pt-9 pb-0">
		<!--begin::Details-->
		<div class="d-flex flex-stack mb-2">
			<!--begin: Pic-->
			<div class="d-none d-md-block me-7 mb-4">
				<div class="position-relative">
					<img src="{{ Module.ModuleLogo|raw }}" alt="image">
				</div>
			</div>
			<!--end::Pic-->
			<!--begin::Info-->
			<div class="flex-grow-1">
				<!--begin::Title-->
				<div class="d-flex justify-content-between align-items-start flex-wrap mb-2">
					<!--begin::User-->
					<div class="d-flex my-4">
						<!--begin::Name-->
						<div class="d-flex align-items-center mb-2">
							<a href="#" class="text-gray-900 text-hover-primary fs-2 fw-bold me-1">{{ Module.ModuleName }}</a>
						</div>
						<!--end::Name-->
					</div>
					<!--end::User-->
					<!--begin::Actions-->
					<div class="d-flex my-4">
						{% for Button in Buttons %}
							<a href="{{ Button.args.href }}" class="{{ Button.args.class }}">{{ Button.title }}</a>
						{% endfor %}
					</div>
					<!--end::Actions-->
				</div>
				<!--end::Title-->
			</div>
			<!--end::Info-->
		</div>
		<!--end::Details-->
		{% if Module.ModuleTabs %}
			<div class="mb-5 hover-scroll-x">
			  <div class="d-grid">
			  	<!--begin::Navs-->
					<ul class="nav nav-stretch nav-line-tabs nav-line-tabs-2x flex-nowrap text-nowrap border-transparent fs-5 fw-bold">
						{% for Tab in Module.ModuleTabs %}
						<!--begin::Nav item-->
						<li class="nav-item mt-2">
							<a class="nav-link text-active-primary ms-0 me-10 py-5 {% if Tab.active == true %}active{% endif %}" href="{{ Tab.link }}">{{ lang(Tab.name) }}</a>
						</li>
						<!--end::Nav item-->
						{% endfor %}
					</ul>
					<!--begin::Navs-->
				</div>
			</div>
		{% endif %}
	</div>
</div>
