import React from 'react';
import Grid from "@material-ui/core/Grid/Grid";
import TextField from "@material-ui/core/TextField/TextField";
import Paper from "@material-ui/core/Paper/Paper";


export default function APILogsParameters(props) {
  const { textCriteria, session, server, fromDate, toDate, onChange, className: classNameProp } = props;


  return (
    <Paper elevation={1} className={classNameProp}>
      <Grid container spacing={16} >
        <Grid item xs={4}>
          <TextField fullWidth
                     name="fromDate"
                     label="From Date"
                     type="date"
                     value={fromDate}
                     onChange={onChange}
                     InputLabelProps={{
                       shrink: true,
                     }}
          />
        </Grid>
        <Grid item xs={4}>
          <TextField
            fullWidth
            name="toDate"
            label="To Date"
            type="date"
            value={toDate}
            onChange={onChange}
            InputLabelProps={{
              shrink: true,
            }}
          />
        </Grid>
        <Grid item xs={4}>
          <TextField
            fullWidth
            label="Serach Criteria"
            id="text-search"
            name={'textCriteria'}
            value={textCriteria}
            onChange={onChange}
          />
        </Grid>
        <Grid item xs={4}>
          <TextField fullWidth
                     name="server"
                     label="Server"
                     value={server}
                     onChange={onChange}
                     InputLabelProps={{
                       shrink: true,
                     }}
          />
        </Grid>
        <Grid item xs={4}>
          <TextField
            fullWidth
            name="session"
            label="Session"
            value={session}
            onChange={onChange}
            InputLabelProps={{
              shrink: true,
            }}
          />
        </Grid>
      </Grid>
    </Paper>
  )
}
