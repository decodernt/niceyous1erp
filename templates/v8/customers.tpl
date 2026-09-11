{% extends 'base.tpl' %}

{% block toolbar %}
	{% include 'Snippets/v8.pagetoolbar.tpl' with { 'PageTitle': Module.ModuleName ~ ' - ' ~ lang('NICEYOUS1ERP_MappedCustomers') } %}
{% endblock %}

{% block content %}
	{% include 'header.tpl' %}

	<div class="card">
		<div class="card-header">
			<div class="card-title">
				<h2>{% lang 'NICEYOUS1ERP_MappedCustomers' %} ({{ customers.total }})</h2>
			</div>
			<div class="card-toolbar">
				<form method="get" action="index.php" class="d-flex align-items-center">
					<input type="hidden" name="ToDo" value="runAddon">
					<input type="hidden" name="addon" value="{{ Module.ModuleId }}">
					<input type="hidden" name="route" value="viewCustomers">
					<input type="text" name="search" value="{{ customers.search }}" class="form-control form-control-sm me-3" placeholder="{% lang 'NICEYOUS1ERP_SearchCustomersPlaceholder' %}">
					<button type="submit" class="btn btn-sm btn-light-primary me-2">{% lang 'NICEYOUS1ERP_Search' %}</button>
					{% if customers.search is not empty %}
						<a href="index.php?ToDo=runAddon&addon={{ Module.ModuleId }}&route=viewCustomers" class="btn btn-sm btn-light">{% lang 'NICEYOUS1ERP_ClearSearch' %}</a>
					{% endif %}
				</form>
			</div>
		</div>
		<div class="card-body">
			<div class="text-muted mb-5">{% lang 'NICEYOUS1ERP_MappedCustomersNote' %}</div>
			{% if customers.rows is not empty %}
				<table class="table table-striped align-middle">
					<thead>
						<tr>
							<th>#</th>
							<th>{% lang 'NICEYOUS1ERP_CustomerName' %}</th>
							<th>{% lang 'NICEYOUS1ERP_CustomerEmail' %}</th>
							<th>{% lang 'NICEYOUS1ERP_CustomerPhone' %}</th>
							<th>{% lang 'NICEYOUS1ERP_ErpCustomerId' %}</th>
							<th>{% lang 'NICEYOUS1ERP_LastUpdate' %}</th>
							<th class="text-end">{% lang 'NICEYOUS1ERP_Actions' %}</th>
						</tr>
					</thead>
					<tbody>
						{% for c in customers.rows %}
							<tr>
								<td><a href="index.php?ToDo=editCustomer&customerId={{ c.customerid }}" target="_blank">{{ c.customerid }}</a></td>
								<td>
									{% if c.orphan %}
										<span class="badge badge-light-danger">{% lang 'NICEYOUS1ERP_OrphanCustomerMapping' %}</span>
									{% else %}
										{{ c.name }}
									{% endif %}
								</td>
								<td>{{ c.email }}</td>
								<td>{{ c.phone }}</td>
								<td><span class="badge badge-light">{{ c.erp_trdr }}</span></td>
								<td>{{ c.last_update }}</td>
								<td class="text-end">
									<a href="index.php?ToDo=runAddon&addon={{ Module.ModuleId }}&route=removeCustomerMapping&customerId={{ c.customerid }}&search={{ customers.search|url_encode }}&page={{ customers.page }}"
										class="btn btn-sm btn-light-danger"
										onclick="return confirm('{% lang 'NICEYOUS1ERP_ConfirmRemoveCustomerMapping' %}');">{% lang 'NICEYOUS1ERP_RemoveMapping' %}</a>
								</td>
							</tr>
						{% endfor %}
					</tbody>
				</table>
			{% else %}
				<div class="text-muted">{% lang 'NICEYOUS1ERP_NoMappings' %}</div>
			{% endif %}
		</div>
		{% include 'Snippets/pagination.tpl' %}
	</div>
{% endblock %}
