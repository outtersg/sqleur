#for AVEC in 0 1
create or replace function xavier(xml T_CLOB) returns T_CLOB PROC_LANG as
#if AVEC
$$
#endif
	declare
		r T_CLOB;
		b0 T_CLOB;
	begin
		return true;
	end;
#if AVEC
$$;
#endif
#done
