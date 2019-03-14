---
layout: page
title: X
permalink: /x/
---
{% for letter-x in site.letter-x %}
<h2>{{ letter-x.title }}</h2>

{{ letter-x.content }}

{% endfor %}