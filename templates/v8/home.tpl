{% extends 'base.tpl' %}

{% block toolbar %}
	{% include 'Snippets/v8.pagetoolbar.tpl' with { 'PageTitle': 'NiceYou SoftOne ERP' } %}
{% endblock %}

{% block content %}
	{% include 'header.tpl' %}

	<div class="card">
		<div class="card-header">
			<div class="card-title">
				<h2>{% lang 'NICEYOUS1ERP_Home' %}</h2>
			</div>
		</div>
		<div class="card-body">
			<div class="mb-5">
				<span class="fw-bold me-2">{% lang 'NICEYOUS1ERP_LastProductPush' %}:</span>
				<span>{{ LastProductPush }}</span>
			</div>
			{% if BackgroundProcess %}
				<div class="alert alert-warning">{{ BackgroundProcess|raw }}</div>
			{% endif %}
		</div>
	</div>
{% endblock %}
