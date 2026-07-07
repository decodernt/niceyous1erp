{% for nagakey, nagavalue in naga.items %}
<div class="d-flex row">
  <div class="col-6">{{ nagavalue.name }}</div>
  <div class="col-6">
    <input type="text" name="{{ inputName }}[{{ nagakey }}]" class="form-control form-control-sm" value="{{ nagavalue.assoc }}" placeholder="{{ placeholder }}">
  </div>
  <div class="separator mb-2 mt-2"></div>
</div>
{% endfor %}
