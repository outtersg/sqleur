--------------------------------------------------------------------------------
-- Définitions selon pilote

#if defined(:pilote)

-- AUTOPRIMARY -----------------------------------------------------------------

#define AUTOPRIMARY_INIT(t, c)

#if :pilote = "pgsql"
#define AUTOPRIMARY serial primary key
#define BIGAUTOPRIMARY bigserial primary key
#endif

#if :pilote = "sqlite"
#define AUTOPRIMARY integer primary key
#define BIGAUTOPRIMARY integer primary key -- https://sqlite.org/forum/info/2dfa968a702e1506e885cb06d92157d492108b22bf39459506ab9f7125bca7fd
#endif

#if :pilote = "oracle"
#define AUTOPRIMARY integer primary key
#define AUTOPRIMARY_INIT(t, c) \
create sequence t##_##c##_seq start with 1;\
create or replace trigger t##_id\
before insert on t\
for each row\
begin\
	select t##_##c##_seq.nextval into :new.c from dual;\
end;\
/
#define BIGAUTOPRIMARY integer primary key
#endif

-- Types simples ---------------------------------------------------------------

#if :pilote = "oracle"
#define T_TEXT varchar2(4000)
#define T_TEXT(x) varchar2(x)
#else
#define T_TEXT text
#define T_TEXT(x) varchar(x)
#endif

-- Fonctions -------------------------------------------------------------------

#if :pilote = "pgsql"
#define MAINTENANT() clock_timestamp()
#elif :pilote = "oracle"
#define MAINTENANT() sysdate
#else
#define MAINTENANT() current_timestamp
#endif

#endif
