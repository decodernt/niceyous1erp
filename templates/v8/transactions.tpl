{% extends 'base.tpl' %}

{% block toolbar %}
	{% include 'Snippets/v8.pagetoolbar.tpl' with { 'PageTitle': Module.ModuleName ~ ' - ' ~ lang('NICEYOUS1ERP_Transactions') } %}
{% endblock %}

{% block content %}
	{% include 'header.tpl' %}

	<div class="card">
		<div class="card-header">
			<div class="card-title">
				<h2>{% lang 'NICEYOUS1ERP_Transactions' %}</h2>
			</div>
		</div>
		<div class="card-body">
			{% if transactions is not empty %}
				<table class="table table-striped">
					<thead>
						<tr>
							<th>#</th>
							<th>{% lang 'NICEYOUS1ERP_Product' %}</th>
							<th>{% lang 'NICEYOUS1ERP_Combination' %}</th>
							<th>{% lang 'NICEYOUS1ERP_Status' %}</th>
							<th>{% lang 'NICEYOUS1ERP_Message' %}</th>
							<th>{% lang 'NICEYOUS1ERP_Response' %}</th>
							<th>{% lang 'NICEYOUS1ERP_Created' %}</th>
						</tr>
					</thead>
					<tbody>
						{% for tr in transactions %}
							<tr>
								<td>{{ tr.transactionid }}</td>
								<td><a href="index.php?ToDo=editProduct&productId={{ tr.productid }}" target="_blank">{{ tr.productid }}</a></td>
								<td>{{ tr.combinationid }}</td>
								<td>
									{% if tr.status == 'DONE' %}
										<span class="badge badge-light-success">{{ tr.status }}</span>
									{% elseif tr.status == 'ERROR' %}
										<span class="badge badge-light-danger">{{ tr.status }}</span>
									{% else %}
										<span class="badge badge-light-warning">{{ tr.status }}</span>
									{% endif %}
								</td>
								<td>{{ tr.message }}</td>
								<td>
									{% if tr.response is not empty %}
										<details>
											<summary class="text-primary cursor-pointer">{% lang 'NICEYOUS1ERP_ResponseShow' %}</summary>
											<pre class="bg-light p-3 rounded mb-0 mt-2" style="max-width: 420px; max-height: 240px; overflow: auto; white-space: pre-wrap; word-break: break-all;">{{ tr.response }}</pre>
										</details>
									{% else %}
										<span class="text-muted">-</span>
									{% endif %}
								</td>
								<td>{{ tr.created }}</td>
							</tr>
						{% endfor %}
					</tbody>
				</table>
			{% else %}
				<div class="text-muted">{% lang 'NICEYOUS1ERP_NoTransactions' %}</div>
			{% endif %}
		</div>
	</div>
{% endblock %}
