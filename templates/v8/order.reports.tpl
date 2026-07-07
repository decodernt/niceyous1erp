{% extends 'base.tpl' %}

{% block toolbar %}
	{% include 'Snippets/v8.pagetoolbar.tpl' with { 'PageTitle': Module.ModuleName ~ ' - ' ~ lang('NICEYOUS1ERP_OrderReports') } %}
{% endblock %}

{% block content %}
	{% include 'header.tpl' %}

	<div class="card">
		<div class="card-header">
			<div class="card-title">
				<h2>{% lang 'NICEYOUS1ERP_OrderReports' %}</h2>
			</div>
			<div class="card-toolbar">
				<form action="index.php" method="get" class="d-flex align-items-center gap-2">
					<input type="hidden" name="ToDo" value="runAddon">
					<input type="hidden" name="addon" value="{{ Module.ModuleId }}">
					<input type="hidden" name="route" value="viewOrderReports">
					<input type="text" name="orderId" class="form-control form-control-sm w-300px" placeholder="Order ID" value="{{ orderId }}">
					<button type="submit" class="btn btn-sm btn-primary">{% lang 'Search' %}</button>
				</form>
			</div>
		</div>
		<div class="card-body">
			{% if orderReports is not empty %}
				<table class="table table-striped">
					<thead>
						<tr>
							<th>{% lang 'NICEYOUS1ERP_OrderId' %}</th>
							<th>{% lang 'NICEYOUS1ERP_ReceiptId' %}</th>
							<th>{% lang 'NICEYOUS1ERP_Message' %}</th>
							<th>{% lang 'NICEYOUS1ERP_Date' %}</th>
						</tr>
					</thead>
					<tbody>
						{% for report in orderReports %}
							<tr>
								<td><a href="index.php?ToDo=viewOrders&searchQuery={{ report.orderId }}" target="_blank">{{ report.orderId }}</a></td>
								<td>{{ report.receiptId }}</td>
								<td>{{ report.message }}</td>
								<td>{{ report.datetime }}</td>
							</tr>
						{% endfor %}
					</tbody>
				</table>
			{% else %}
				<div class="text-muted">{% lang 'NICEYOUS1ERP_NoOrderReports' %}</div>
			{% endif %}
		</div>
	</div>
{% endblock %}
