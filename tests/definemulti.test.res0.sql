select
$$
select 1;
select 2;
$$--#
select 1--#
select 2--#
create or replace function gaffe() returns trigger as
$$
	begin
		if trouille then
			se carapater;
		else
			rien finalement;
		end if;
	end;
$$--#
