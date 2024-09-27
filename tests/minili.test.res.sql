select ','||cast(listagg(code, ',,') within group (order by code ) as varchar2(127))||',';
