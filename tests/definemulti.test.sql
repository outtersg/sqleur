#define REQS \
select 1;\
select 2;

select
$$
REQS
$$;
REQS

-- Gère-t-on aussi les béguins à cheval entre un #define et du direct?

#define si_trouille \
	if trouille then\
		se carapater;

create or replace function gaffe() returns trigger as
$$
	begin
		si_trouille
		else
			rien finalement;
		end if;
	end;
$$;
