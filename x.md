---
layout: page
title: X
permalink: /x/
---
{% for letter-x in site.letter-x %}
<h2><a href="{{ letter-x.url }}">{{ letter-x.title }}</a></h2>

{{ letter-x.content }}

{% endfor %}